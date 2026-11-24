<?php

declare(strict_types=1);

namespace App\Provider\Ats;

use App\DTO\JobDto;
use Psr\Log\LoggerInterface;
use App\Monitoring\FetchDiagnostics;
use App\Provider\JobProviderInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Socle des providers d'ATS (Greenhouse, Lever, Ashby, Recruitee, Workable, SmartRecruiters) : API publiques de job boards, sans clé,
 * interrogées pour une liste configurable de boards d'entreprises.
 *
 * Aucun scraping : uniquement les API publiques documentées par chaque éditeur, sur des hôtes
 * fixes. Les boards sont validés (voir `AtsBoard`). Un board en échec n'empêche pas les autres :
 * son erreur est relevée pour le monitoring (collecte partielle). Une liste de boards vide
 * désactive le provider.
 *
 * Collecte incrémentale : non applicable, aucune de ces API n'offre de filtre par date ; la
 * déduplication du pipeline écarte les offres déjà connues.
 */
abstract class AbstractAtsProvider implements JobProviderInterface
{
    private const int MAX_ATTEMPTS = 3;
    private const int MAX_RETRY_WAIT_SECONDS = 10;
    private const int MAX_TITLE_LENGTH = 255;
    private const int MAX_DESCRIPTION_LENGTH = 20000;

    private ?float $lastRequestAt = null;

    /** @var list<AtsBoard> */
    private readonly array $boards;

    /**
     * @param list<string|null> $boards Entrées `identifiant` ou `identifiant=Entreprise` ; vide = provider désactivé
     * @param null|\Closure(int): void $sleeper Attente injectable pour les tests (millisecondes)
     */
    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
        array $boards = [],
        protected readonly int $minRequestIntervalMs = 200,
        private readonly ?\Closure $sleeper = null,
        private readonly ?FetchDiagnostics $diagnostics = null,
    ) {
        $valid = [];
        foreach ($boards as $entry) {
            $board = AtsBoard::tryFromConfig($entry);
            if ($board !== null) {
                $valid[$board->token] = $board;
            } elseif (trim((string) $entry) !== '') {
                $this->logger->warning('Board ATS ignoré : identifiant invalide.', ['provider' => $this->name()]);
            }
        }

        $this->boards = array_values($valid);
    }

    /**
     * Offres d'un board, ou `null` si la collecte de ce board a échoué (erreur déjà relevée).
     *
     * @return list<JobDto>|null
     */
    abstract protected function fetchBoard(AtsBoard $board): ?array;

    /** URL légère servant à vérifier la disponibilité d'un board. */
    abstract protected function probeUrl(AtsBoard $board): string;

    /**
     * Sain si le provider est désactivé (le run l'enregistre alors « désactivé »), ou si au moins
     * un board répond : un board invalide ne doit pas mettre les autres hors service.
     */
    public function isHealthy(): bool
    {
        if ($this->boards === []) {
            return true;
        }

        foreach ($this->boards as $board) {
            try {
                if ($this->httpClient->request('GET', $this->probeUrl($board), [
                    'headers' => $this->headers(),
                    'timeout' => 5,
                ])->getStatusCode() < 400) {
                    return true;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Sonde ATS en échec.', ['provider' => $this->name(), 'error' => $e->getMessage()]);
            }
        }

        return false;
    }

    /** @return JobDto[] */
    public function fetch(): array
    {
        if ($this->boards === []) {
            $this->diagnostics?->markDisabled($this->name());

            return [];
        }

        $jobs = [];
        foreach ($this->boards as $board) {
            try {
                $boardJobs = $this->fetchBoard($board);
            } catch (\Throwable $e) {
                $this->fail($board, $e->getMessage());

                continue;
            }

            foreach ($boardJobs ?? [] as $job) {
                $jobs[$job->url] = $job;
            }
        }

        return array_values($jobs);
    }

    /**
     * GET JSON avec limitation de débit, retry avec backoff (429, 5xx, erreurs réseau) et relevé
     * des échecs. Les autres erreurs 4xx (board inconnu…) ne sont pas retentées.
     *
     * @param array<string, scalar> $query
     * @return array<mixed>|null
     */
    protected function getJson(AtsBoard $board, string $url, array $query = []): ?array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $this->respectRateLimit();

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'query' => $query,
                    'headers' => $this->headers(),
                    'timeout' => 20,
                ]);
                $status = $response->getStatusCode();
            } catch (\Throwable $e) {
                $this->logger->warning('ATS : erreur réseau.', ['provider' => $this->name(), 'board' => $board->token, 'attempt' => $attempt, 'error' => $e->getMessage()]);
                if ($attempt === self::MAX_ATTEMPTS) {
                    $this->fail($board, 'erreur réseau : ' . $e->getMessage());

                    return null;
                }
                $this->wait($this->backoffMs($attempt));

                continue;
            }

            if ($status === 200) {
                return $this->decode($board, $response);
            }

            if (($status === 429 || $status >= 500) && $attempt < self::MAX_ATTEMPTS) {
                $this->wait($this->retryDelayMs($response, $attempt));

                continue;
            }

            $this->logger->warning('ATS : réponse refusée.', ['provider' => $this->name(), 'board' => $board->token, 'status' => $status]);
            $this->fail($board, sprintf('HTTP %d', $status));

            return null;
        }

        return null;
    }

    /**
     * Texte brut d'un contenu HTML, y compris HTML échappé en entités (Greenhouse). Les blocs sont
     * séparés par un espace pour ne pas coller les mots ; tout balisage est retiré.
     */
    protected function plainText(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('#<\s*(?:br|/p|/li|/div|/h[1-6]|/tr)\b[^>]*>#i', ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    protected function title(mixed $value): ?string
    {
        $title = $this->plainText($value);

        return $title === '' ? null : mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }

    protected function optionalText(mixed $value): ?string
    {
        $text = $this->plainText($value);

        return $text === '' ? null : $text;
    }

    /**
     * Assemble la description : texte de l'offre puis informations structurées fournies par l'API
     * (contrat, mode de travail, salaire…), que le texte ne mentionne pas toujours.
     *
     * @param list<string|null> $parts
     */
    protected function description(array $parts): string
    {
        $text = implode(' ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));

        return mb_substr($text, 0, self::MAX_DESCRIPTION_LENGTH);
    }

    /** « Étiquette : valeur. » pour une information structurée de l'API, ou `null` si elle est absente. */
    protected function labelled(string $label, mixed $value): ?string
    {
        $text = $this->optionalText($value);

        return $text === null ? null : sprintf('%s : %s.', $label, $text);
    }

    /**
     * Un identifiant de board inséré dans un **nom d'hôte** (`{id}.exemple.com`) doit être une étiquette DNS valide :
     * sans `.` ni `_`, pour ne jamais sortir du domaine de l'éditeur.
     */
    protected function isHostLabel(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $token) === 1;
    }

    protected function isHttpUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    protected function parseDate(mixed $value): ?\DateTimeImmutable
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

    protected function fail(AtsBoard $board, string $message): void
    {
        $this->diagnostics?->record($this->name(), sprintf('%s : %s', $board->token, $message));
    }

    /** @return array<mixed>|null */
    private function decode(AtsBoard $board, ResponseInterface $response): ?array
    {
        try {
            return $response->toArray(false);
        } catch (\Throwable $e) {
            $this->fail($board, 'réponse JSON invalide');
            $this->logger->warning('ATS : JSON invalide.', ['provider' => $this->name(), 'board' => $board->token, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Accept' => 'application/json', 'User-Agent' => 'JOBSCAN/1.0'];
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
}
