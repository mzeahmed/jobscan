<?php

declare(strict_types=1);

namespace App\Provider\Ats;

use App\DTO\JobDto;
use Psr\Log\LoggerInterface;
use App\Monitoring\FetchDiagnostics;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Offres d'un site Lever (Postings API, publique, sans authentification pour la lecture).
 *
 * `GET https://api.lever.co/v0/postings/{site}?mode=json&skip=&limit=` (région EU :
 * `api.eu.lever.co`) — documentation : https://github.com/lever/postings-api (vérifiée).
 * Seules les offres au statut « published » sont publiques.
 *
 * Non documenté, donc non utilisé : le champ `createdAt`, la taille maximale de `limit` et une
 * limite de débit. La date de publication reste **inconnue**. La pagination (`skip`/`limit`) n'a
 * ni total ni curseur : elle s'arrête sur une page vide, sans nouvelle offre, ou au maximum de pages.
 */
final class LeverProvider extends AbstractAtsProvider
{
    private const string GLOBAL_URL = 'https://api.lever.co/v0/postings/';
    private const string EU_URL = 'https://api.eu.lever.co/v0/postings/';

    /**
     * @param list<string|null> $boards
     * @param string $region `global` ou `eu`
     */
    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        array $boards = [],
        int $minRequestIntervalMs = 200,
        ?\Closure $sleeper = null,
        ?FetchDiagnostics $diagnostics = null,
        private readonly string $region = 'global',
        private readonly int $pageSize = 100,
        private readonly int $maxPages = 10,
    ) {
        parent::__construct($httpClient, $logger, $boards, $minRequestIntervalMs, $sleeper, $diagnostics);
    }

    public function name(): string
    {
        return 'lever';
    }

    protected function probeUrl(AtsBoard $board): string
    {
        return $this->baseUrl() . $board->token . '?mode=json&limit=1';
    }

    protected function fetchBoard(AtsBoard $board): ?array
    {
        $size = max(1, $this->pageSize);
        $jobs = [];

        for ($page = 0; $page < max(1, $this->maxPages); ++$page) {
            $data = $this->getJson($board, $this->baseUrl() . $board->token, [
                'mode' => 'json',
                'skip' => $page * $size,
                'limit' => $size,
            ]);

            if ($data === null) {
                // Échec d'une page : on garde ce qui a été collecté, l'erreur est déjà relevée.
                return $jobs === [] ? null : array_values($jobs);
            }

            $added = 0;
            foreach ($data as $offer) {
                $job = is_array($offer) ? $this->toJob($board, $offer) : null;
                if ($job !== null && !isset($jobs[$job->externalId])) {
                    $jobs[(string) $job->externalId] = $job;
                    ++$added;
                }
            }

            // Page vide, ou l'API ignore `skip` et renvoie les mêmes offres : fin de la liste.
            if ($data === [] || $added === 0) {
                break;
            }
        }

        return array_values($jobs);
    }

    /**
     * @param array<mixed> $offer
     */
    private function toJob(AtsBoard $board, array $offer): ?JobDto
    {
        $id = $offer['id'] ?? null;
        $title = $this->title($offer['text'] ?? null);
        $url = $offer['hostedUrl'] ?? null;
        if (!is_string($id) || $id === '' || $title === null || !$this->isHttpUrl($url)) {
            return null;
        }

        $categories = is_array($offer['categories'] ?? null) ? $offer['categories'] : [];
        $locations = array_filter(array_map($this->optionalText(...), (array) ($categories['allLocations'] ?? [])));
        $location = $this->optionalText($categories['location'] ?? null) ?? ($locations === [] ? null : implode(', ', $locations));

        return new JobDto(
            title: $title,
            url: (string) $url,
            description: $this->description([
                $this->plainText($offer['descriptionPlain'] ?? $offer['description'] ?? null),
                ...$this->lists($offer),
                $this->plainText($offer['additionalPlain'] ?? $offer['additional'] ?? null),
                $this->labelled('Contrat', $categories['commitment'] ?? null),
                $this->labelled('Équipe', $categories['team'] ?? null),
                $this->workplace($offer['workplaceType'] ?? null),
                $this->salary($offer),
            ]),
            source: $this->name(),
            company: $board->company,
            location: $location,
            externalId: $id,
        );
    }

    /**
     * @param array<mixed> $offer
     * @return list<string|null>
     */
    private function lists(array $offer): array
    {
        $parts = [];
        foreach ((array) ($offer['lists'] ?? []) as $list) {
            if (is_array($list)) {
                $parts[] = trim($this->plainText($list['text'] ?? null) . ' : ' . $this->plainText($list['content'] ?? null), ' :');
            }
        }

        return $parts;
    }

    private function workplace(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && $value !== 'unspecified' ? sprintf('Mode de travail : %s.', $value) : null;
    }

    /**
     * @param array<mixed> $offer
     */
    private function salary(array $offer): ?string
    {
        $description = $this->optionalText($offer['salaryDescriptionPlain'] ?? $offer['salaryDescription'] ?? null);
        if ($description !== null) {
            return 'Salaire : ' . $description;
        }

        $range = $offer['salaryRange'] ?? null;
        if (!is_array($range) || !is_numeric($range['min'] ?? null) || !is_numeric($range['max'] ?? null)) {
            return null;
        }

        return sprintf(
            'Salaire : de %s à %s %s (%s).',
            $range['min'],
            $range['max'],
            is_string($range['currency'] ?? null) ? $range['currency'] : '',
            is_string($range['interval'] ?? null) ? $range['interval'] : 'période non précisée',
        );
    }

    private function baseUrl(): string
    {
        return $this->region === 'eu' ? self::EU_URL : self::GLOBAL_URL;
    }
}
