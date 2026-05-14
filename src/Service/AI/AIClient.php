<?php

declare(strict_types=1);

namespace App\Service\AI;

use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Analyse le texte d'une offre d'emploi via un LLM local (LM Studio).
 *
 * Extrait les données structurées suivantes : stack technique, type de contrat,
 * indicateurs remote/freelance/recent, budget et séniorité.
 *
 * **Stratégie de résilience :**
 * - Le résultat est mis en cache 24h (clé = SHA-256 du texte nettoyé) pour éviter
 *   d'interroger le LLM plusieurs fois pour la même offre.
 * - Si LM Studio est indisponible ou retourne une réponse non parseable, un fallback
 *   heuristique basé sur des regex et des correspondances de chaînes prend le relais.
 * - Les erreurs LLM ne propagent jamais d'exception vers le pipeline.
 *
 * Le prompt système est entièrement configurable via `app.ai_system_prompt` dans
 * `jobscan.yaml`, sans modifier le code.
 */
final class AIClient
{
    /** Durée de mise en cache des réponses IA en secondes (24h). */
    private const CACHE_TTL = 86400;

    /**
     * @param string       $apiBase      URL de base de l'API LM Studio (env `AI_API_BASE`)
     * @param string       $apiKey       Clé d'API (env `AI_API_KEY` — peut être vide pour LM Studio local)
     * @param string       $model        Identifiant du modèle à utiliser (env `AI_MODEL`)
     * @param string       $systemPrompt Prompt système injecté en tête de chaque requête (config `app.ai_system_prompt`)
     * @param list<string> $knownStack   Technologies connues pour le fallback heuristique (config `app.known_stack`)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $apiBase,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $systemPrompt,
        private readonly array $knownStack = [],
    ) {
    }

    /**
     * Analyse une description d'offre et retourne les données structurées extraites.
     *
     * Le texte est nettoyé et tronqué à 3 000 caractères avant envoi.
     * En cas de cache hit, le LLM n'est pas sollicité.
     * En cas d'échec LLM, le fallback heuristique est automatiquement utilisé.
     *
     * @return array{stack: list<string>, contract_type: string, freelance: bool, remote: bool, budget: string, recent: bool, seniority: string}
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function analyze(string $text): array
    {
        $text = $this->cleanText($text);
        $text = mb_substr($text, 0, 3000);

        $cacheKey = 'ai_' . hash('sha256', $text);
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            $this->logger->debug('AIClient: cache hit.', ['key' => $cacheKey]);

            return $item->get();
        }

        $result = $this->callLMStudio($text);

        if ($result !== null) {
            $item->set($result)->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);

            return $result;
        }

        return $this->heuristicFallback($text);
    }

    /**
     * Envoie le texte au LLM via l'API compatible OpenAI de LM Studio.
     *
     * Tente deux passes de parsing sur la réponse :
     *   1. `json_decode` direct sur le contenu brut
     *   2. Extraction par regex d'un bloc JSON si le LLM a ajouté du texte autour
     *
     * Retourne `null` si la réponse est non parseable ou si une exception est levée.
     *
     * @return array{stack: list<string>, contract_type: string, freelance: bool, remote: bool, budget: string, recent: bool, seniority: string}|null
     */
    private function callLMStudio(string $text): ?array
    {
        try {
            $response = $this->httpClient
                ->request('POST', rtrim($this->apiBase, '/') . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $this->model,
                        'messages' => [
                            ['role' => 'system', 'content' => $this->systemPrompt],
                            ['role' => 'user', 'content' => $text],
                        ],
                        'temperature' => 0,
                        'max_tokens' => 256,
                    ],
                    'timeout' => 120,
                    'max_duration' => 120,
                ]);

            $data = $response->toArray(false);
            $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

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
        } catch (\Throwable $e) {
            $this->logger->warning('AIClient::analyze() a échoué, fallback heuristique.', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Normalise et type-safe la réponse brute du LLM.
     *
     * Garantit que chaque champ est présent avec le bon type, quelle que soit
     * la qualité de la réponse du modèle. Les valeurs `contract_type` et
     * `seniority` hors vocabulaire contrôlé sont ramenées à `'unknown'`.
     *
     * @param array<string, mixed> $data Tableau décodé depuis la réponse JSON du LLM
     * @return array{stack: list<string>, contract_type: string, freelance: bool, remote: bool, budget: string, recent: bool, seniority: string}
     */
    private function normalize(array $data): array
    {
        $contractType = strtolower((string) ($data['contract_type'] ?? 'unknown'));
        if (!\in_array($contractType, ['freelance', 'cdi', 'unknown'], true)) {
            $contractType = 'unknown';
        }

        $seniority = strtolower((string) ($data['seniority'] ?? 'unknown'));
        if (!\in_array($seniority, ['junior', 'mid', 'senior', 'unknown'], true)) {
            $seniority = 'unknown';
        }

        return [
            'stack' => array_values(array_unique(array_map(
                static fn ($item) => strtolower(trim((string) $item)),
                (array) ($data['stack'] ?? [])
            ))),
            'contract_type' => $contractType,
            'freelance' => (bool) ($data['freelance'] ?? false),
            'remote' => (bool) ($data['remote'] ?? false),
            'budget' => (string) ($data['budget'] ?? 'non précisé'),
            'recent' => (bool) ($data['recent'] ?? true),
            'seniority' => $seniority,
        ];
    }

    /**
     * Fallback heuristique activé quand LM Studio est indisponible ou retourne une réponse invalide.
     *
     * Reproduit une extraction partielle basée sur des correspondances de chaînes :
     *   - Type de contrat : détection de `freelance`, `mission`, `tjm`, `cdi`
     *   - Séniorité : détection de `senior`, `confirmé`, `junior`, `débutant`, `mid`
     *   - Stack : intersection du texte avec `app.known_stack`
     *   - Budget : extraction regex (TJM `€/j`, fourchette `80-110k`, montant `50k`)
     *   - Remote : détection de `remote`, `télétravail`
     *
     * Le champ `recent` vaut toujours `true` — sans IA, l'information n'est pas déductible.
     *
     * @return array{stack: list<string>, contract_type: string, freelance: bool, remote: bool, budget: string, recent: bool, seniority: string}
     */
    private function heuristicFallback(string $text): array
    {
        $lower = strtolower($text);
        $freelance = str_contains($lower, 'freelance') || str_contains($lower, 'mission') || str_contains($lower, 'tjm');
        $cdi = str_contains($lower, 'cdi') || str_contains($lower, 'contrat à durée indéterminée');

        if ($freelance) {
            $contractType = 'freelance';
        } elseif ($cdi) {
            $contractType = 'cdi';
        } else {
            $contractType = 'unknown';
        }

        $seniority = 'unknown';
        if (str_contains($lower, 'senior') || str_contains($lower, 'confirmé') || str_contains($lower, 'confirme')) {
            $seniority = 'senior';
        } elseif (str_contains($lower, 'junior') || str_contains($lower, 'débutant') || str_contains($lower, 'debutant')) {
            $seniority = 'junior';
        } elseif (str_contains($lower, 'mid') || str_contains($lower, 'intermédiaire') || str_contains($lower, 'intermediaire')) {
            $seniority = 'mid';
        }

        return [
            'stack' => $this->extractStack($lower),
            'contract_type' => $contractType,
            'freelance' => $freelance,
            'remote' => str_contains($lower, 'remote')
                        || str_contains($lower, 'télétravail')
                        || str_contains($lower, 'teletravail'),
            'budget' => $this->extractBudget($lower),
            'recent' => true,
            'seniority' => $seniority,
        ];
    }

    /**
     * Extrait les technologies présentes dans le texte par intersection avec `app.known_stack`.
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
        if (preg_match('/(\d{3,4})\s*€?\s*\/?\s*j(our)?/i', $text, $matches)) {
            return $matches[1] . '€/j';
        }

        if (preg_match('/(\d{2,3})\s*[-–]\s*(\d{2,3})\s*k/i', $text, $matches)) {
            return $matches[1] . '-' . $matches[2] . 'k€/an';
        }

        if (preg_match('/(\d{2,3})\s*k\s*€?/i', $text, $matches)) {
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
}
