<?php

declare(strict_types=1);

namespace App\AI;

use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use Psr\Log\LoggerInterface;
use App\AI\Extraction\JobExtraction;
use App\AI\Extraction\PromptBuilder;
use Psr\Cache\CacheItemPoolInterface;
use App\AI\Extraction\JobRequirements;
use App\AI\Provider\LLMClientInterface;
use Psr\Cache\InvalidArgumentException;
use App\AI\Extraction\LegacyAnalysisMapper;
use App\AI\Extraction\RequirementsValidator;
use App\AI\Extraction\PromptInjectionDetector;
use App\AI\Extraction\InvalidExtractionException;

/**
 * Extrait les exigences d'une annonce via un moteur LLM pluggable (`LLMClientInterface` — Ollama,
 * LM Studio ou Gemini, sélectionné par `DEFAULT_LLM_PROVIDER`, voir `LLMClientFactory`).
 *
 * L'extraction est **indépendante du profil candidat** : une annonce n'est analysée qu'une fois, quel
 * que soit le nombre de profils de recherche. Elle produit des `JobRequirements` (compétences exigées
 * ou appréciées, séniorité, télétravail, contrats, rémunération…) validés et ancrés dans le texte de
 * l'annonce, ainsi que la vue historique `AiAnalysisDto` encore utilisée par le scoring existant.
 *
 * **Stratégie de résilience :**
 * - Le résultat du LLM est mis en cache 24h (clé = schéma, prompt et texte) pour ne pas interroger
 *   plusieurs fois le modèle sur la même annonce.
 * - Si le LLM est indisponible ou renvoie une réponse inexploitable, un fallback heuristique prend le
 *   relais (moins précis : il ne distingue pas compétences exigées et appréciées) ; il n'est jamais mis en cache.
 * - Les erreurs LLM ne propagent jamais d'exception vers le pipeline.
 *
 * **Sécurité :** l'annonce est une donnée non fiable — voir `PromptBuilder` et `RequirementsValidator`.
 */
final class AIClient
{
    /** Durée de mise en cache des réponses IA en secondes (24h). */
    private const int CACHE_TTL = 86400;

    private const string SUSPICIOUS_WARNING = 'Annonce contenant une consigne adressée à un modèle d\'IA : passage retiré de l\'analyse, extraction à vérifier.';

    private bool $providerUnavailable = false;
    private bool $lastAnalysisUsedFallback = false;
    private readonly PromptBuilder $promptBuilder;
    private readonly RequirementsValidator $validator;
    private readonly PromptInjectionDetector $injectionDetector;

    /**
     * @param  string  $systemPrompt  Prompt de la tâche d'extraction (config `app.ai_system_prompt`)
     * @param  list<string>  $knownStack  Technologies connues pour le fallback heuristique (config `app.profile.known_stack`)
     * @param  int  $maxInputChars  Longueur maximale de l'annonce envoyée au modèle (config `app.profile.ai_max_input_chars`)
     */
    public function __construct(
        private readonly LLMClientInterface $provider,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $systemPrompt,
        private readonly array $knownStack = [],
        private readonly int $recentJobDays = 14,
        private readonly int $maxInputChars = 6000,
        ?PromptBuilder $promptBuilder = null,
        ?RequirementsValidator $validator = null,
    ) {
        $this->promptBuilder = $promptBuilder ?? new PromptBuilder($this->systemPrompt);
        $this->validator = $validator ?? new RequirementsValidator();
        $this->injectionDetector = new PromptInjectionDetector();
    }

    /**
     * Vue historique de l'extraction (stack, contrat, remote, budget, séniorité).
     *
     * @throws InvalidArgumentException
     */
    public function analyze(string $text, ?\DateTimeImmutable $publishedAt = null): AiAnalysisDto
    {
        return $this->extract($text, $publishedAt)->analysis;
    }

    /**
     * Extrait les exigences structurées d'une annonce.
     *
     * Le texte est nettoyé et tronqué à `maxInputChars` avant envoi. En cas de cache hit, le LLM n'est
     * pas sollicité. En cas d'échec LLM, le fallback heuristique est automatiquement utilisé.
     *
     * @throws InvalidArgumentException
     */
    public function extract(string $text, ?\DateTimeImmutable $publishedAt = null): JobExtraction
    {
        $this->lastAnalysisUsedFallback = false;
        $text = mb_substr($this->cleanText($text), 0, max(1, $this->maxInputChars));

        // Une consigne adressée à un modèle est retirée du texte avant l'envoi et avant l'ancrage des résultats.
        [$text, $suspicious] = $this->injectionDetector->neutralize($text);
        if ($suspicious) {
            $this->logger->warning('AIClient: annonce contenant une consigne adressée à un modèle d\'IA, passage retiré.');
        }

        $cacheKey = 'ai_v' . JobRequirements::SCHEMA_VERSION . '_' . hash('sha256', $this->promptBuilder->systemPrompt() . "\0" . $text);
        $item = $this->cache->getItem($cacheKey);

        $cached = $item->isHit() ? $item->get() : null;
        if ($cached instanceof JobRequirements) {
            $this->logger->debug('AIClient: cache hit.', ['key' => $cacheKey]);

            return $this->build($cached, $publishedAt, false, $suspicious);
        }

        if (!$this->providerUnavailable) {
            $requirements = $this->callAI($text);

            if ($requirements !== null) {
                $item->set($requirements)->expiresAfter(self::CACHE_TTL);
                $this->cache->save($item);

                return $this->build($requirements, $publishedAt, false, $suspicious);
            }
        }

        $this->lastAnalysisUsedFallback = true;

        // En repli, la vue historique est l'analyse heuristique elle-même (elle porte le budget détecté,
        // que les exigences minimales qui en sont tirées n'ont pas).
        $analysis = $this->heuristicFallback($text);

        return $this->build(LegacyAnalysisMapper::toRequirements($analysis), $publishedAt, true, $suspicious, $analysis);
    }

    public function lastAnalysisUsedFallback(): bool
    {
        return $this->lastAnalysisUsedFallback;
    }

    /**
     * Oublie l'indisponibilité mémorisée du provider IA.
     *
     * Le drapeau évite de marteler un LLM tombé pendant un lot ; il doit être remis à zéro
     * entre deux lots pour qu'un processus de longue durée puisse détecter son retour.
     */
    public function resetProviderAvailability(): void
    {
        $this->providerUnavailable = false;
    }

    private function build(
        JobRequirements $requirements,
        ?\DateTimeImmutable $publishedAt,
        bool $usedFallback,
        bool $suspicious,
        ?AiAnalysisDto $analysis = null,
    ): JobExtraction {
        if ($suspicious) {
            $requirements = $requirements->withWarnings([self::SUSPICIOUS_WARNING]);
        }

        $recent = $this->isRecent($publishedAt);
        $analysis = $analysis === null
            ? LegacyAnalysisMapper::toAnalysis($requirements, $recent)
            : new AiAnalysisDto(
                $analysis->stack,
                $analysis->contractType,
                $analysis->freelance,
                $analysis->remote,
                $analysis->budget,
                $recent,
                $analysis->seniority,
            );

        return new JobExtraction($requirements, $analysis, $usedFallback);
    }

    /**
     * Envoie l'annonce au moteur LLM actif (Ollama, LM Studio ou Gemini) et valide sa réponse.
     *
     * Tente deux passes de décodage sur la réponse :
     *   1. `json_decode` direct sur le contenu brut
     *   2. Extraction par regex d'un bloc JSON si le LLM a ajouté du texte autour
     *
     * Retourne `null` si la réponse est absente, non décodable ou inexploitable après validation.
     */
    private function callAI(string $text): ?JobRequirements
    {
        $content = $this->provider->analyze($this->promptBuilder->systemPrompt(), $this->promptBuilder->userMessage($text));

        if ($content === null) {
            $this->providerUnavailable = true;
            $this->logger->warning('AIClient: provider IA indisponible ou réponse vide, fallback heuristique.');

            return null;
        }

        $parsed = $this->decode($content);
        if ($parsed === null) {
            $this->logger->warning('AIClient: réponse non décodable, fallback heuristique.', ['length' => mb_strlen($content)]);

            return null;
        }

        try {
            return $this->validator->validate($parsed, $text);
        } catch (InvalidExtractionException $e) {
            $this->logger->warning('AIClient: réponse inexploitable, fallback heuristique.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<array-key, mixed>|null */
    private function decode(string $content): ?array
    {
        $parsed = json_decode($content, true);
        if (\is_array($parsed)) {
            return $parsed;
        }

        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $parsed = json_decode($matches[0], true);

            if (\is_array($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Fallback heuristique activé quand le provider IA est indisponible ou retourne une réponse invalide.
     *
     * Reproduit une extraction partielle basée sur des correspondances de chaînes :
     *   - Type de contrat : détection de `freelance`, `mission`, `tjm`, `cdi`
     *   - Séniorité : détection de `senior`, `confirmé`, `junior`, `débutant`, `mid`
     *   - Stack : intersection du texte avec `app.profile.known_stack`
     *   - Budget : extraction regex (TJM `€/j`, fourchette `80-110k`, montant `50k`)
     *   - Remote : détection de `remote`, `télétravail`
     *
     * Le champ `recent` est ensuite calculé depuis la date de publication, indépendamment du LLM.
     */
    private function heuristicFallback(string $text): AiAnalysisDto
    {
        $lower = strtolower($text);
        $freelance = str_contains($lower, 'freelance') || str_contains($lower, 'mission') || str_contains($lower, 'tjm');
        $cdi = str_contains($lower, 'cdi') || str_contains($lower, 'contrat à durée indéterminée');

        $contractType = match (true) {
            $freelance => ContractType::Freelance,
            $cdi => ContractType::Cdi,
            default => ContractType::Unknown,
        };

        $seniority = match (true) {
            str_contains($lower, 'senior') || str_contains($lower, 'confirmé') || str_contains($lower, 'confirme') => Seniority::Senior,
            str_contains($lower, 'junior') || str_contains($lower, 'débutant') || str_contains($lower, 'debutant') => Seniority::Junior,
            str_contains($lower, 'mid') || str_contains($lower, 'intermédiaire') || str_contains($lower, 'intermediaire') => Seniority::Mid,
            default => Seniority::Unknown,
        };

        return new AiAnalysisDto(
            stack: $this->extractStack($lower),
            contractType: $contractType,
            freelance: $freelance,
            remote: str_contains($lower, 'remote')
                        || str_contains($lower, 'télétravail')
                        || str_contains($lower, 'teletravail'),
            budget: $this->extractBudget($lower),
            recent: false,
            seniority: $seniority,
        );
    }

    /**
     * Extrait les technologies présentes dans le texte par intersection avec `app.profile.known_stack`.
     *
     * @return list<string>
     */
    private function extractStack(string $text): array
    {
        return array_values(array_filter(
            $this->knownStack,
            static fn (string $tech) => str_contains($text, $tech)
        ));
    }

    /**
     * Tente d'extraire un budget depuis le texte brut.
     *
     * Patterns reconnus dans l'ordre de priorité :
     *   - TJM journalier : `450€/j`, `500 €/jour`
     *   - Fourchette annuelle : `80-110k`, `80k-110k€`
     *   - Montant annuel simple : `50k€`, `45k`
     *
     * Retourne `'non précisé'` si aucun pattern ne correspond.
     */
    private function extractBudget(string $text): string
    {
        if (preg_match('/(\d{3,4})\s*€?\s*\/?\s*j(our)?/iu', $text, $matches)) {
            return $matches[1] . '€/j';
        }

        if (preg_match('/(\d{2,3})\s*[-–]\s*(\d{2,3})\s*k/iu', $text, $matches)) {
            return $matches[1] . '-' . $matches[2] . 'k€/an';
        }

        if (preg_match('/(\d{2,3})\s*k\s*€?/iu', $text, $matches)) {
            return $matches[1] . 'k€/an';
        }

        return 'non précisé';
    }

    /**
     * Nettoie le texte avant envoi au LLM : décode les entités HTML,
     * supprime les balises et normalise les espaces.
     */
    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }

    private function isRecent(?\DateTimeImmutable $publishedAt): bool
    {
        return $publishedAt !== null
            && $publishedAt <= new \DateTimeImmutable()
            && $publishedAt >= new \DateTimeImmutable(sprintf('-%d days', $this->recentJobDays));
    }
}
