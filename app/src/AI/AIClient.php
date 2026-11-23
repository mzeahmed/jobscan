<?php

declare(strict_types=1);

namespace App\AI;

use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use App\Scoring\TextMatcher;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use App\AI\Provider\LLMClientInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Analyse le texte d'une offre d'emploi via un moteur LLM pluggable
 * (`LLMClientInterface` — Ollama, LM Studio ou Gemini, sélectionné par
 * `DEFAULT_LLM_PROVIDER`, voir `LLMClientFactory`).
 *
 * Extrait les données structurées suivantes : stack technique, type de contrat,
 * indicateurs remote/freelance/recent, budget et séniorité.
 *
 * **Stratégie de résilience :**
 * - Le résultat est mis en cache 24h (clé = SHA-256 du texte nettoyé) pour éviter
 *   d'interroger le LLM plusieurs fois pour la même offre.
 * - Si le provider IA est indisponible ou retourne une réponse non parseable, un fallback
 *   heuristique basé sur des regex et des correspondances de chaînes prend le relais.
 * - Les erreurs LLM ne propagent jamais d'exception vers le pipeline.
 *
 * Le prompt système est entièrement configurable via `app.ai_system_prompt` dans
 * `jobscan.yaml`, sans modifier le code.
 */
final class AIClient
{
    /** Durée de mise en cache des réponses IA en secondes (24h). */
    private const int CACHE_TTL = 86400;

    private bool $providerUnavailable = false;
    private bool $lastAnalysisUsedFallback = false;

    /**
     * @param  string  $systemPrompt  Prompt système injecté en tête de chaque requête (config `app.ai_system_prompt`)
     * @param  list<string>  $knownStack  Technologies connues pour le fallback heuristique (config `app.profile.known_stack`)
     */
    public function __construct(
        private readonly LLMClientInterface $provider,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $systemPrompt,
        private readonly array $knownStack = [],
        private readonly int $recentJobDays = 14,
    ) {
    }

    /**
     * Analyse une description d'offre et retourne les données structurées extraites.
     *
     * Le titre, l'entreprise et le lieu (s'ils sont fournis) précèdent la description,
     * qui est nettoyée et tronquée à 3 000 caractères avant envoi.
     * En cas de cache hit, le LLM n'est pas sollicité.
     * En cas d'échec LLM, le fallback heuristique est automatiquement utilisé.
     *
     * @throws InvalidArgumentException
     */
    public function analyze(
        string $text,
        ?\DateTimeImmutable $publishedAt = null,
        ?string $title = null,
        ?string $company = null,
        ?string $location = null,
    ): AiAnalysisDto {
        $this->lastAnalysisUsedFallback = false;
        $text = $this->buildInput($this->cleanText($text), $title, $company, $location);

        // Préfixe versionné : les entrées de cache antérieures n'ont pas les champs de pertinence.
        $cacheKey = 'ai_v2_' . hash('sha256', $text);
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            $this->logger->debug('AIClient: cache hit.', ['key' => $cacheKey]);

            return $this->withDeterministicRecency($item->get(), $publishedAt);
        }

        if ($this->providerUnavailable) {
            $this->lastAnalysisUsedFallback = true;
            return $this->withDeterministicRecency($this->heuristicFallback($text), $publishedAt);
        }

        $result = $this->callAI($text);

        if ($result !== null) {
            $item->set($result)->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);

            return $this->withDeterministicRecency($result, $publishedAt);
        }

        $this->lastAnalysisUsedFallback = true;

        return $this->withDeterministicRecency($this->heuristicFallback($text), $publishedAt);
    }

    public function lastAnalysisUsedFallback(): bool
    {
        return $this->lastAnalysisUsedFallback;
    }

    /**
     * Envoie le texte au moteur LLM actif (Ollama, LM Studio ou Gemini).
     *
     * Tente deux passes de parsing sur la réponse :
     *   1. `json_decode` direct sur le contenu brut
     *   2. Extraction par regex d'un bloc JSON si le LLM a ajouté du texte autour
     *
     * Retourne `null` si la réponse est non parseable ou si une exception est levée.
     */
    private function callAI(string $text): ?AiAnalysisDto
    {
        $content = $this->provider->analyze($this->systemPrompt, $text);

        if ($content === null) {
            $this->providerUnavailable = true;
            $this->logger->warning('AIClient: provider IA indisponible ou réponse vide, fallback heuristique.');

            return null;
        }

        $parsed = json_decode($content, true);
        if (\is_array($parsed)) {
            $this->logger->debug('AIClient: réponse parsée avec succès.', [
                'content' => $content,
                'parsed' => $parsed,
            ]);

            return $this->normalize($parsed);
        }

        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $parsed = json_decode($matches[0], true);

            if (\is_array($parsed)) {
                $this->logger->debug('AIClient: réponse parsée avec succès après extraction heuristique.', [
                    'content' => $content,
                    'extracted' => $matches[0],
                    'parsed' => $parsed,
                ]);

                return $this->normalize($parsed);
            }
        }

        $this->logger->warning('AIClient: réponse non parseable, fallback heuristique.', [
            'content' => $content,
        ]);

        return null;
    }

    /**
     * Normalise et type-safe la réponse brute du LLM.
     *
     * Garantit que chaque champ est présent avec le bon type, quelle que soit
     * la qualité de la réponse du modèle. Les valeurs `contract_type` et
     * `seniority` hors vocabulaire contrôlé sont ramenées à `Unknown`.
     *
     * @param  array<string, mixed>  $data  Tableau décodé depuis la réponse JSON du LLM
     */
    private function normalize(array $data): AiAnalysisDto
    {
        $contractType = ContractType::tryFrom(strtolower((string) ($data['contract_type'] ?? 'unknown')))
            ?? ContractType::Unknown;

        $seniority = Seniority::tryFrom(strtolower((string) ($data['seniority'] ?? 'unknown')))
            ?? Seniority::Unknown;

        return new AiAnalysisDto(
            stack: array_values(array_unique(array_map(
                static fn ($item) => strtolower(trim((string) $item)),
                (array) ($data['stack'] ?? [])
            ))),
            contractType: $contractType,
            freelance: (bool) ($data['freelance'] ?? false),
            remote: (bool) ($data['remote'] ?? false),
            budget: (string) ($data['budget'] ?? 'non précisé'),
            recent: false,
            seniority: $seniority,
            primaryLanguage: $this->normalizeLanguage($data['primary_language'] ?? null),
            relevance: $this->normalizeRelevance($data['relevance'] ?? null),
            locationOk: \is_bool($data['location_ok'] ?? null) ? $data['location_ok'] : null,
        );
    }

    private function normalizeLanguage(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value === '' ? null : $value;
    }

    private function normalizeRelevance(mixed $value): ?int
    {
        if (!\is_int($value) && !(\is_string($value) && is_numeric($value)) && !\is_float($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    /**
     * Assemble l'entrée du LLM : métadonnées de l'offre (titre, entreprise, lieu) puis
     * description tronquée à 3 000 caractères.
     */
    private function buildInput(string $description, ?string $title, ?string $company, ?string $location): string
    {
        $header = [];
        foreach (['Titre' => $title, 'Entreprise' => $company, 'Lieu' => $location] as $label => $value) {
            $value = $value === null ? '' : $this->cleanText($value);
            if ($value !== '') {
                $header[] = $label . ' : ' . $value;
            }
        }

        $description = mb_substr($description, 0, 3000);

        return $header === [] ? $description : implode("\n", $header) . "\nDescription : " . $description;
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
        $lower = TextMatcher::normalize($text);
        $has = static fn (string ...$words): bool => array_any(
            $words,
            static fn (string $word): bool => TextMatcher::containsNormalized($lower, $word),
        );

        $freelance = $has('freelance', 'mission', 'tjm');
        $cdi = $has('cdi', 'contrat a duree indeterminee');

        $contractType = match (true) {
            $freelance => ContractType::Freelance,
            $cdi => ContractType::Cdi,
            default => ContractType::Unknown,
        };

        $seniority = match (true) {
            $has('senior', 'confirme') => Seniority::Senior,
            $has('junior', 'debutant') => Seniority::Junior,
            $has('mid', 'intermediaire') => Seniority::Mid,
            default => Seniority::Unknown,
        };

        return new AiAnalysisDto(
            stack: $this->extractStack($lower),
            contractType: $contractType,
            freelance: $freelance,
            remote: $has('remote', 'teletravail'),
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
            static fn (string $tech) => TextMatcher::containsNormalized($text, $tech)
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

    private function withDeterministicRecency(AiAnalysisDto $analysis, ?\DateTimeImmutable $publishedAt): AiAnalysisDto
    {
        $recent = $publishedAt !== null
            && $publishedAt <= new \DateTimeImmutable()
            && $publishedAt >= new \DateTimeImmutable(sprintf('-%d days', $this->recentJobDays));

        return new AiAnalysisDto(
            stack: $analysis->stack,
            contractType: $analysis->contractType,
            freelance: $analysis->freelance,
            remote: $analysis->remote,
            budget: $analysis->budget,
            recent: $recent,
            seniority: $analysis->seniority,
            primaryLanguage: $analysis->primaryLanguage,
            relevance: $analysis->relevance,
            locationOk: $analysis->locationOk,
        );
    }
}
