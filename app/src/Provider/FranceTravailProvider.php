<?php

declare(strict_types=1);

namespace App\Provider;

use App\DTO\JobDto;
use Psr\Log\LoggerInterface;
use App\DTO\PublicationDateSource;
use App\Monitoring\FetchDiagnostics;
use Psr\Cache\CacheItemPoolInterface;
use App\DTO\PublicationDateConfidence;
use App\Repository\ProviderRunRepository;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Offres de l'API « Offres d'emploi » (v2) de France Travail.
 *
 * ⚠ NON VALIDÉ CONTRE LA DOCUMENTATION OFFICIELLE. Le portail francetravail.io n'a pas pu
 * être lu lors de l'écriture (rendu JavaScript) et la fiche data.gouv.fr indique que l'API
 * n'est « plus exposée le temps d'une analyse des modalités de distribution et de
 * réutilisation ». Les éléments suivants viennent de la connaissance de l'API v2 et sont à
 * confirmer avec de vrais identifiants et la documentation, avant toute activation :
 *   - URL du jeton, `realm=/partenaire` et `scope` (configurables) ;
 *   - chemin `/offres/search`, paramètres `motsCles`, `departement`, `range`,
 *     `minCreationDate` / `maxCreationDate` (format `Y-m-d\TH:i:s\Z`), plafond de 150 offres par page ;
 *   - codes 200 / 206 (contenu partiel) / 204 (aucun résultat), en-tête `Content-Range` ;
 *   - champs `resultats[].{id,intitule,description,dateCreation,lieuTravail,entreprise,origineOffre}` ;
 *   - URL publique de repli de l'offre ;
 *   - quota de 10 appels par seconde (fiche data.gouv.fr) ; les conditions de réutilisation.
 *
 * Désactivé par défaut (`FRANCE_TRAVAIL_ENABLED`). Aucun secret ni jeton n'est journalisé.
 * Collecte incrémentale : seules les offres créées depuis la dernière exécution réussie
 * (avec un recouvrement d'une heure) sont demandées ; sans historique, la fenêtre est
 * `lookbackDays`.
 */
final class FranceTravailProvider implements JobProviderInterface
{
    public const string DEFAULT_TOKEN_URL = 'https://entreprise.francetravail.fr/connexion/oauth2/access_token';
    public const string DEFAULT_API_URL = 'https://api.francetravail.io/partenaire/offresdemploi/v2';
    public const string DEFAULT_SCOPE = 'api_offresdemploiv2 o2dsoffre';

    private const string TOKEN_CACHE_KEY = 'france_travail_access_token';
    private const string OFFER_URL = 'https://candidat.francetravail.fr/offres/recherche/detail/';
    private const int MAX_ATTEMPTS = 3;
    private const int MAX_RETRY_WAIT_SECONDS = 10;
    private const int INCREMENTAL_OVERLAP_SECONDS = 3600;

    private ?float $lastRequestAt = null;

    /**
     * @param list<string|null> $keywords Mots-clés de recherche (une requête par mot-clé) ; vide = recherche non filtrée
     * @param list<string|null> $departments Codes départements (une requête par département) ; vide = toute la France
     * @param int $pageSize Offres par page (150 maximum côté API)
     * @param null|\Closure(int): void $sleeper Attente injectable pour les tests (millisecondes)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
        private readonly bool $enabled = false,
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
        private readonly string $scope = self::DEFAULT_SCOPE,
        private readonly string $tokenUrl = self::DEFAULT_TOKEN_URL,
        private readonly string $apiUrl = self::DEFAULT_API_URL,
        private readonly array $keywords = [],
        private readonly array $departments = [],
        private readonly int $maxPages = 3,
        private readonly int $pageSize = 150,
        private readonly int $lookbackDays = 7,
        private readonly int $minRequestIntervalMs = 150,
        private readonly ?\Closure $sleeper = null,
        private readonly ?FetchDiagnostics $diagnostics = null,
        private readonly ?ProviderRunRepository $providerRuns = null,
    ) {
    }

    public function name(): string
    {
        return 'francetravail';
    }

    /**
     * Désactivé : sain (le run enregistre alors « désactivé », pas un échec).
     * Activé : sain si un jeton d'accès peut être obtenu.
     */
    public function isHealthy(): bool
    {
        if (!$this->enabled) {
            return true;
        }

        return $this->hasCredentials() && $this->accessToken() !== null;
    }

    /** @return JobDto[] */
    public function fetch(): array
    {
        if (!$this->enabled) {
            $this->diagnostics?->markDisabled($this->name());

            return [];
        }

        if (!$this->hasCredentials()) {
            $this->fail('Identifiants France Travail manquants (FRANCE_TRAVAIL_CLIENT_ID / FRANCE_TRAVAIL_CLIENT_SECRET).');

            return [];
        }

        $now = new \DateTimeImmutable();
        $since = $this->incrementalSince($now);
        $jobs = [];

        foreach ($this->searchCombinations() as [$keyword, $department]) {
            foreach ($this->searchAllPages($keyword, $department, $since, $now) as $offer) {
                $job = $this->toJob($offer);
                if ($job !== null) {
                    $jobs[$job->externalId ?? $job->url] = $job;
                }
            }
        }

        return array_values($jobs);
    }

    /**
     * @return list<array{0: ?string, 1: ?string}>
     */
    private function searchCombinations(): array
    {
        $keywords = $this->cleanList($this->keywords) ?: [null];
        $departments = $this->cleanList($this->departments) ?: [null];

        $combinations = [];
        foreach ($keywords as $keyword) {
            foreach ($departments as $department) {
                $combinations[] = [$keyword, $department];
            }
        }

        return $combinations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchAllPages(?string $keyword, ?string $department, \DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        $pageSize = max(1, min(150, $this->pageSize));
        $offers = [];

        for ($page = 0; $page < max(1, $this->maxPages); ++$page) {
            $first = $page * $pageSize;
            $query = array_filter([
                'motsCles' => $keyword,
                'departement' => $department,
                'minCreationDate' => $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'maxCreationDate' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'range' => sprintf('%d-%d', $first, $first + $pageSize - 1),
            ], static fn (?string $value): bool => $value !== null);

            $response = $this->searchPage($query);
            if ($response === null) {
                break;
            }

            $offers = [...$offers, ...$response['results']];

            $reachedEnd = count($response['results']) < $pageSize
                || ($response['total'] !== null && $first + $pageSize >= $response['total']);
            if ($reachedEnd) {
                break;
            }
        }

        return $offers;
    }

    /**
     * @param array<string, string> $query
     * @return array{results: list<array<string, mixed>>, total: ?int}|null null en cas d'échec définitif
     */
    private function searchPage(array $query): ?array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $token = $this->accessToken();
            if ($token === null) {
                return null;
            }

            $this->respectRateLimit();

            try {
                $response = $this->httpClient->request('GET', rtrim($this->apiUrl, '/') . '/offres/search', [
                    'query' => $query,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                        'User-Agent' => 'JOBSCAN/1.0',
                    ],
                    'timeout' => 20,
                ]);
                $status = $response->getStatusCode();
            } catch (\Throwable $e) {
                $this->logger->warning('FranceTravailProvider : erreur réseau.', ['attempt' => $attempt, 'error' => $e->getMessage()]);
                if ($attempt === self::MAX_ATTEMPTS) {
                    $this->fail('Erreur réseau : ' . $e->getMessage());

                    return null;
                }
                $this->wait($this->backoffMs($attempt));

                continue;
            }

            if ($status === 200 || $status === 206) {
                return $this->parsePage($response);
            }

            if ($status === 204) {
                return ['results' => [], 'total' => 0];
            }

            if ($status === 401 && $attempt < self::MAX_ATTEMPTS) {
                $this->cache->deleteItem(self::TOKEN_CACHE_KEY);

                continue;
            }

            if (($status === 429 || $status >= 500) && $attempt < self::MAX_ATTEMPTS) {
                $this->wait($this->retryDelayMs($response, $attempt));

                continue;
            }

            $this->logger->warning('FranceTravailProvider : réponse refusée.', ['status' => $status]);
            $this->fail(sprintf('API France Travail : HTTP %d.', $status));

            return null;
        }

        return null;
    }

    /**
     * @return array{results: list<array<string, mixed>>, total: ?int}|null
     */
    private function parsePage(ResponseInterface $response): ?array
    {
        try {
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->fail('Réponse JSON invalide : ' . $e->getMessage());

            return null;
        }

        $results = [];
        foreach ((array) ($data['resultats'] ?? []) as $offer) {
            if (is_array($offer)) {
                $results[] = $offer;
            }
        }

        $total = null;
        $range = $response->getHeaders(false)['content-range'][0] ?? null;
        if (is_string($range) && preg_match('#/(\d+)\s*$#', $range, $matches) === 1) {
            $total = (int) $matches[1];
        }

        return ['results' => $results, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function toJob(array $offer): ?JobDto
    {
        $id = $this->text($offer['id'] ?? null);
        $title = $this->text($offer['intitule'] ?? null);
        if ($id === null || $title === null) {
            return null;
        }

        $origin = is_array($offer['origineOffre'] ?? null) ? $offer['origineOffre'] : [];
        $url = $this->text($origin['urlOrigine'] ?? null) ?? self::OFFER_URL . rawurlencode($id);
        if (!$this->isHttpUrl($url)) {
            return null;
        }

        $publishedAt = $this->parseDate($offer['dateCreation'] ?? null);
        $company = is_array($offer['entreprise'] ?? null) ? $this->text($offer['entreprise']['nom'] ?? null) : null;
        $place = is_array($offer['lieuTravail'] ?? null) ? $this->text($offer['lieuTravail']['libelle'] ?? null) : null;

        return new JobDto(
            title: $title,
            url: $url,
            description: $this->description($offer),
            source: $this->name(),
            publishedAt: $publishedAt,
            company: $company,
            location: $place,
            externalId: $id,
            publicationDateSource: $publishedAt === null ? PublicationDateSource::Unknown : PublicationDateSource::Api,
            publicationDateConfidence: $publishedAt === null ? PublicationDateConfidence::Unknown : PublicationDateConfidence::High,
        );
    }

    /**
     * Description de l'offre complétée du contrat et du salaire quand l'API les fournit :
     * le texte seul ne les mentionne pas toujours, et l'analyse en a besoin.
     *
     * @param array<string, mixed> $offer
     */
    private function description(array $offer): string
    {
        $parts = [$this->text($offer['description'] ?? null) ?? ''];

        $contract = $this->text($offer['typeContratLibelle'] ?? null);
        if ($contract !== null) {
            $parts[] = 'Contrat : ' . $contract . '.';
        }

        $salary = is_array($offer['salaire'] ?? null) ? $this->text($offer['salaire']['libelle'] ?? null) : null;
        if ($salary !== null) {
            $parts[] = 'Salaire : ' . $salary . '.';
        }

        return trim(implode(' ', array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    private function incrementalSince(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $fallback = $now->modify(sprintf('-%d days', max(1, $this->lookbackDays)));

        try {
            $lastSuccess = $this->providerRuns?->findLastSuccessByProvider()[$this->name()] ?? null;
        } catch (\Throwable) {
            return $fallback;
        }

        if ($lastSuccess === null) {
            return $fallback;
        }

        $since = $lastSuccess->modify(sprintf('-%d seconds', self::INCREMENTAL_OVERLAP_SECONDS));

        return max($since, $fallback);
    }

    private function accessToken(): ?string
    {
        try {
            $item = $this->cache->getItem(self::TOKEN_CACHE_KEY);
            if ($item->isHit() && is_string($item->get())) {
                return $item->get();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('FranceTravailProvider : cache du jeton indisponible.', ['error' => $e->getMessage()]);
        }

        try {
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                'query' => ['realm' => '/partenaire'],
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => (string) $this->clientId,
                    'client_secret' => (string) $this->clientSecret,
                    'scope' => $this->scope,
                ],
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'JOBSCAN/1.0'],
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->fail('Authentification France Travail impossible : ' . $e->getMessage());

            return null;
        }

        $token = $data['access_token'] ?? null;
        if ($status !== 200 || !is_string($token) || $token === '') {
            $this->logger->warning('FranceTravailProvider : jeton refusé.', ['status' => $status]);
            $this->fail(sprintf('Authentification France Travail refusée (HTTP %d).', $status));

            return null;
        }

        $expiresIn = is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 1500;

        try {
            $item = $this->cache->getItem(self::TOKEN_CACHE_KEY);
            $item->set($token)->expiresAfter(max(30, $expiresIn - 60));
            $this->cache->save($item);
        } catch (\Throwable) {
            // Sans cache, un nouveau jeton sera demandé à la prochaine requête.
        }

        return $token;
    }

    private function hasCredentials(): bool
    {
        return trim((string) $this->clientId) !== '' && trim((string) $this->clientSecret) !== '';
    }

    private function respectRateLimit(): void
    {
        if ($this->lastRequestAt !== null && $this->minRequestIntervalMs > 0) {
            $elapsedMs = (int) ((microtime(true) - $this->lastRequestAt) * 1000);
            if ($elapsedMs < $this->minRequestIntervalMs) {
                $this->wait($this->minRequestIntervalMs - $elapsedMs);
            }
        }

        $this->lastRequestAt = microtime(true);
    }

    private function retryDelayMs(ResponseInterface $response, int $attempt): int
    {
        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
        if (is_string($retryAfter) && ctype_digit($retryAfter)) {
            return min((int) $retryAfter, self::MAX_RETRY_WAIT_SECONDS) * 1000;
        }

        return $this->backoffMs($attempt);
    }

    private function backoffMs(int $attempt): int
    {
        return min(self::MAX_RETRY_WAIT_SECONDS * 1000, 500 * (2 ** ($attempt - 1)));
    }

    private function wait(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }

    private function fail(string $message): void
    {
        $this->diagnostics?->record($this->name(), $message);
    }

    /**
     * @param list<string|null> $values
     * @return list<string>
     */
    private function cleanList(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn (?string $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return $text === '' ? null : $text;
    }

    private function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
