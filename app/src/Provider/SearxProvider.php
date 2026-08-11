<?php

declare(strict_types=1);

namespace App\Provider;

use App\DTO\JobDto;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Agrège les offres d'emploi via l'API JSON de SearXNG.
 *
 * Les requêtes non localisées combinent `app.profile.searx_queries` et
 * `app.profile.job_locations`, dans la limite du quota configuré. Les réponses
 * sont mises en cache et exécutées par lots concurrents. Le filtre `time_range=month`
 * est envoyé nativement à SearXNG pour limiter les résultats aux offres récentes.
 *
 * Les résultats sont dédupliqués par URL avant d'être retournés. Les résultats
 * manifestement hors-sujet (tutoriels, documentation, etc.) sont écartés via
 * une liste de patterns bloquants avant d'atteindre le pipeline.
 */
final readonly class SearxProvider implements JobProviderInterface
{
    public function name(): string
    {
        return 'searxng';
    }

    /**
     * @param string $baseUrl URL de base de l'instance SearXNG (env `SEARXNG_URL`)
     * @param list<string> $searchQueries Requêtes de base (config `app.profile.searx_queries`)
     * @param list<string> $locations Localisations (config `app.profile.job_locations`)
     * @param null|\Closure(int): void $sleeper Attente injectable pour les tests (durée en millisecondes)
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $baseUrl,
        private CacheItemPoolInterface $cache,
        private array $searchQueries = [],
        private array $locations = [],
        private int $maxQueries = 20,
        private int $concurrency = 5,
        private int $cacheTtl = 3600,
        private int $maxPages = 2,
        private int $queryDelayMs = 500,
        private ?\Closure $sleeper = null,
    ) {
    }

    /**
     * Exécute toutes les requêtes combinées et retourne les offres dédupliquées.
     *
     * @return JobDto[]
     */
    public function fetch(): array
    {
        $jobs = [];

        foreach ($this->searchMany($this->buildQueries()) as $results) {
            foreach ($results as $result) {
                $title = trim((string) ($result['title'] ?? ''));
                $url = trim((string) ($result['url'] ?? ''));
                $description = trim((string) ($result['content'] ?? ''));

                if ($title === '' || $url === '') {
                    continue;
                }

                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    continue;
                }

                if ($this->isClearlyIrrelevant($title, $url, $description)) {
                    continue;
                }

                $publishedAt = $this->extractPublishedDate($result);
                $company = $this->optionalString($result['company'] ?? $result['author'] ?? null);
                $location = $this->optionalString($result['location'] ?? null);

                $jobs[$url] = new JobDto(
                    title: $this->cleanText($title),
                    url: $url,
                    description: $this->cleanText($description),
                    source: 'searxng',
                    publishedAt: $publishedAt,
                    company: $company,
                    location: $location,
                );
            }
        }

        return array_values($jobs);
    }

    /**
     * Exécute les pages par lots concurrents. Une requête quitte la pagination
     * dès qu'une page est vide ou en échec, sans interrompre les autres.
     *
     * @param list<string> $queries
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function searchMany(array $queries): array
    {
        $allResults = array_fill_keys($queries, []);
        $activeQueries = $queries;
        $hasDispatchedBatch = false;

        for ($page = 1; $page <= max(1, $this->maxPages) && $activeQueries !== []; ++$page) {
            $nextPageQueries = [];

            foreach (array_chunk($activeQueries, max(1, $this->concurrency)) as $chunk) {
                $pageResults = $this->searchPageChunk($chunk, $page, $hasDispatchedBatch);

                foreach ($chunk as $query) {
                    $results = $pageResults[$query] ?? [];
                    if ($results === []) {
                        continue;
                    }

                    $allResults[$query] = array_merge($allResults[$query], $results);
                    $nextPageQueries[] = $query;
                }
            }

            $activeQueries = $nextPageQueries;
        }

        return $allResults;
    }

    /**
     * @param list<string> $queries
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function searchPageChunk(array $queries, int $page, bool &$hasDispatchedBatch): array
    {
        $pageResults = [];
        $cacheMisses = [];

        foreach ($queries as $query) {
            $cacheKey = 'searx_' . hash('sha256', $this->baseUrl . '|' . $query . '|' . $page);

            try {
                $item = $this->cache->getItem($cacheKey);
                if ($item->isHit() && is_array($item->get())) {
                    $pageResults[$query] = $item->get();
                    continue;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Cache SearXNG indisponible.', ['error' => $e->getMessage()]);
            }

            $cacheMisses[$query] = $cacheKey;
        }

        if ($cacheMisses === []) {
            return $pageResults;
        }

        if ($hasDispatchedBatch) {
            $this->waitBetweenBatches();
        }

        $hasDispatchedBatch = true;

        /** @var array<string, array{response: ResponseInterface, cache_key: string}> $pending */
        $pending = [];

        foreach ($cacheMisses as $query => $cacheKey) {
            try {
                $pending[$query] = [
                    'response' => $this->httpClient->request('GET', rtrim($this->baseUrl, '/') . '/search', [
                        'query' => [
                            'q' => $query,
                            'format' => 'json',
                            'language' => 'fr-FR',
                            'safesearch' => 0,
                            'time_range' => 'month',
                            'pageno' => $page,
                        ],
                        'headers' => [
                            'Accept' => 'application/json',
                            'User-Agent' => 'JOBSCAN/1.0',
                        ],
                        'timeout' => 20,
                    ]),
                    'cache_key' => $cacheKey,
                ];
            } catch (\Throwable $e) {
                $pageResults[$query] = [];
                $this->logSearchFailure($query, $page, $e);
            }
        }

        foreach ($pending as $query => $request) {
            try {
                $data = $request['response']->toArray(false);
                $results = isset($data['results']) && is_array($data['results']) ? $data['results'] : [];
                $pageResults[$query] = $results;

                $item = $this->cache->getItem($request['cache_key']);
                $item->set($results)->expiresAfter($this->cacheTtl);
                $this->cache->save($item);
            } catch (\Throwable $e) {
                $pageResults[$query] = [];
                $this->logSearchFailure($query, $page, $e);
            }
        }

        return $pageResults;
    }

    private function waitBetweenBatches(): void
    {
        if ($this->queryDelayMs <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($this->queryDelayMs);

            return;
        }

        usleep($this->queryDelayMs * 1000);
    }

    private function logSearchFailure(string $query, int $page, \Throwable $error): void
    {
        $this->logger->warning('SearxProvider search failed.', [
            'query' => $query,
            'page' => $page,
            'error' => $error->getMessage(),
        ]);
    }

    /**
     * Détermine si un résultat est manifestement hors-sujet.
     *
     * La détection se fait en deux passes :
     *   1. Présence d'un pattern bloquant (tutoriels, docs, Wikipedia…) → rejeté
     *   2. Absence de tout signal emploi (job, emploi, freelance, CDI…) → rejeté
     *
     * Un résultat qui ne contient aucun pattern bloquant mais au moins un signal
     * emploi est considéré comme potentiellement pertinent.
     */
    private function isClearlyIrrelevant(string $title, string $url, string $description): bool
    {
        $text = strtolower($title . ' ' . $url . ' ' . $description);

        $blockedPatterns = [
            'tutorial',
            'cours',
            'formation',
            'manual',
            'documentation',
            'wikipedia',
            'youtube.com',
            'openclassrooms.com',
            'w3schools.com',
            'geeksforgeeks.org',
            'php.net',
            'github.com/php',
        ];

        foreach ($blockedPatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        $jobSignals = [
            'job',
            'jobs',
            'emploi',
            'emplois',
            'recrute',
            'hiring',
            'remote',
            'freelance',
            'mission',
            'cdi',
            'developer',
            'développeur',
            'backend',
            'full stack',
            'fullstack',
        ];
        return array_all($jobSignals, fn ($signal) => !str_contains($text, (string) $signal));
    }

    /**
     * Parse une date depuis une chaîne brute en tentant plusieurs formats.
     *
     * Ordre des tentatives :
     *   1. `DateTimeImmutable` natif (ISO 8601, RFC 2822, etc.)
     *   2. Regex mois français littéral : `12 janvier 2026`
     *   3. Regex séparateur slash : `mm/dd/yyyy` (SearXNG) puis `dd/mm/yyyy`
     *
     * Retourne `null` si aucun format ne correspond, sans jamais lever d'exception.
     */
    private function parsePublishedDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
        }

        if (preg_match(
            '/(\d{1,2})\s+(janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre)\s+(\d{4})/iu',
            $raw,
            $m
        )) {
            $months = [
                'janvier' => '01',
                'février' => '02',
                'mars' => '03',
                'avril' => '04',
                'mai' => '05',
                'juin' => '06',
                'juillet' => '07',
                'août' => '08',
                'septembre' => '09',
                'octobre' => '10',
                'novembre' => '11',
                'décembre' => '12',
            ];

            $month = mb_strtolower($m[2]);

            if (isset($months[$month])) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%s-%s-%02d', $m[3], $months[$month], (int) $m[1]));
                if ($date !== false) {
                    return $date;
                }
            }
        }

        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $raw, $m)) {
            $normalized = sprintf('%02d/%02d/%s', (int) $m[1], (int) $m[2], $m[3]);
            // Essaye mm/dd/yyyy (format SearXNG), puis dd/mm/yyyy
            foreach (['!m/d/Y', '!d/m/Y'] as $fmt) {
                $date = \DateTimeImmutable::createFromFormat($fmt, $normalized);
                if ($date !== false) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * Tente d'extraire une date de publication depuis un résultat SearXNG.
     *
     * Sonde les champs dans l'ordre de fiabilité décroissante :
     * `publishedDate` → `pubdate` → `metadata` → `content` → `title`.
     * Un log debug est émis quand la date provient d'un champ de fallback.
     *
     * @param array<string, mixed> $result
     */
    private function extractPublishedDate(array $result): ?\DateTimeImmutable
    {
        $candidates = [
            'publishedDate' => $result['publishedDate'] ?? null,
            'pubdate' => $result['pubdate'] ?? null,
            'metadata' => $result['metadata'] ?? null,
            'content' => $result['content'] ?? null,
            'title' => $result['title'] ?? null,
        ];

        foreach ($candidates as $field => $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $date = $this->parsePublishedDate($candidate);

            if ($date === null) {
                continue;
            }

            if ($field !== 'publishedDate') {
                $this->logger->debug('Date extraite depuis le champ "{field}" : {date}', [
                    'field' => $field,
                    'date' => $date->format('Y-m-d'),
                    'url' => $result['url'] ?? '',
                ]);
            }

            return $date;
        }

        return null;
    }

    /**
     * Nettoie un texte brut : décode les entités HTML, supprime les balises
     * et normalise les espaces multiples.
     */
    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = $this->cleanText($value);

        return $value === '' ? null : $value;
    }

    /**
     * Construit le produit cartésien requêtes × localisations.
     *
     * Exemple : 2 requêtes × 3 localisations → 6 requêtes uniques envoyées à SearXNG.
     * Les doublons éventuels sont supprimés avant retour.
     *
     * @return list<string>
     */
    private function buildQueries(): array
    {
        $queries = [];

        foreach ($this->searchQueries as $baseQuery) {
            $baseQuery = trim($baseQuery);
            if ($baseQuery === '') {
                continue;
            }

            $alreadyLocalized = array_any(
                $this->locations,
                static fn (string $location): bool => $location !== '' && str_contains(mb_strtolower($baseQuery), mb_strtolower($location))
            );

            if ($alreadyLocalized || $this->locations === []) {
                $queries[] = $baseQuery;
                continue;
            }

            foreach ($this->locations as $location) {
                $queries[] = trim($baseQuery . ' ' . $location);
            }
        }

        return array_slice(array_values(array_unique($queries)), 0, max(0, $this->maxQueries));
    }
}
