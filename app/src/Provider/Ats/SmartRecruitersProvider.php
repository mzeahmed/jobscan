<?php

declare(strict_types=1);

namespace App\Provider\Ats;

use App\DTO\JobDto;
use Psr\Log\LoggerInterface;
use App\DTO\PublicationDateSource;
use App\Monitoring\FetchDiagnostics;
use App\DTO\PublicationDateConfidence;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Offres d'une entreprise SmartRecruiters (Posting API, publique, sans clé pour la lecture).
 *
 * - Liste : `GET https://api.smartrecruiters.com/v1/companies/{id}/postings?limit=&offset=` (forme **observée sur une
 *   entreprise réelle** : `totalFound`, `content[]` avec `id`, `name`, `releasedDate`, `location`, `department`,
 *   `typeOfEmployment`, `experienceLevel`…). La liste ne contient **pas** la description.
 * - Détail : `GET …/postings/{id}` (`postingUrl`, `jobAd.sections.*.text`) : une requête par offre.
 *
 * Pour borner le coût (une requête par offre, 200 ms entre deux), le détail n'est demandé que pour les `maxDetails`
 * premières offres de chaque board ; les autres sont importées avec les seules informations structurées de la liste
 * (intitulé, lieu, contrat, niveau, département), sans description. Une offre importée ainsi reste sans description.
 * Les URL de détail sont construites localement : le champ `ref` renvoyé par l'API n'est jamais suivi.
 *
 * `releasedDate` est la date de mise en ligne : date de publication de confiance moyenne.
 */
final class SmartRecruitersProvider extends AbstractAtsProvider
{
    private const string BASE_URL = 'https://api.smartrecruiters.com/v1/companies/';
    private const string PUBLIC_URL = 'https://jobs.smartrecruiters.com/';
    private const int MAX_CONSECUTIVE_DETAIL_FAILURES = 3;

    /** @param list<string|null> $boards */
    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        array $boards = [],
        int $minRequestIntervalMs = 200,
        ?\Closure $sleeper = null,
        ?FetchDiagnostics $diagnostics = null,
        private readonly int $pageSize = 100,
        private readonly int $maxPages = 10,
        private readonly int $maxDetails = 30,
    ) {
        parent::__construct($httpClient, $logger, $boards, $minRequestIntervalMs, $sleeper, $diagnostics);
    }

    public function name(): string
    {
        return 'smartrecruiters';
    }

    protected function probeUrl(AtsBoard $board): string
    {
        return self::BASE_URL . $board->token . '/postings?limit=1';
    }

    protected function fetchBoard(AtsBoard $board): ?array
    {
        $size = max(1, min(100, $this->pageSize));
        $postings = [];

        for ($page = 0; $page < max(1, $this->maxPages); ++$page) {
            $data = $this->getJson($board, self::BASE_URL . $board->token . '/postings', ['limit' => $size, 'offset' => $page * $size]);
            if ($data === null) {
                return $postings === [] ? null : $this->build($board, $postings);
            }

            $content = array_values(array_filter((array) ($data['content'] ?? []), is_array(...)));
            foreach ($content as $posting) {
                $postings[] = $posting;
            }

            $total = is_numeric($data['totalFound'] ?? null) ? (int) $data['totalFound'] : null;
            if ($content === [] || count($content) < $size || ($total !== null && ($page + 1) * $size >= $total)) {
                break;
            }
        }

        return $this->build($board, $postings);
    }

    /**
     * @param list<array<mixed>> $postings
     *
     * @return list<JobDto>
     */
    private function build(AtsBoard $board, array $postings): array
    {
        $jobs = [];
        $detailsLeft = max(0, $this->maxDetails);
        $failures = 0;

        foreach ($postings as $posting) {
            $id = $posting['id'] ?? null;
            // Pas de requête de détail pour une offre qu'on n'importera pas de toute façon.
            if (!is_string($id) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) !== 1 || $this->title($posting['name'] ?? null) === null) {
                continue;
            }

            $detail = null;
            if ($detailsLeft > 0 && $failures < self::MAX_CONSECUTIVE_DETAIL_FAILURES) {
                --$detailsLeft;
                $detail = $this->getJson($board, self::BASE_URL . $board->token . '/postings/' . $id);
                $failures = $detail === null ? $failures + 1 : 0;
            }

            $job = $this->toJob($board, $id, $posting, $detail);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * @param array<mixed> $posting
     * @param array<mixed>|null $detail
     */
    private function toJob(AtsBoard $board, string $id, array $posting, ?array $detail): ?JobDto
    {
        $title = $this->title($posting['name'] ?? null);
        if ($title === null) {
            return null;
        }

        $url = is_string($detail['postingUrl'] ?? null) && $this->isHttpUrl($detail['postingUrl'])
            ? $detail['postingUrl']
            : self::PUBLIC_URL . $board->token . '/' . $id;

        $location = is_array($posting['location'] ?? null) ? $posting['location'] : [];
        $publishedAt = $this->parseDate($posting['releasedDate'] ?? null);
        $companyName = is_array($posting['company'] ?? null) ? $this->optionalText($posting['company']['name'] ?? null) : null;

        return new JobDto(
            title: $title,
            url: $url,
            description: $this->description([
                $this->sections($detail),
                $this->labelled('Contrat', $this->label($posting['typeOfEmployment'] ?? null)),
                $this->labelled('Niveau d\'expérience', $this->label($posting['experienceLevel'] ?? null)),
                $this->labelled('Département', $this->label($posting['department'] ?? null)),
                $this->labelled('Fonction', $this->label($posting['function'] ?? null)),
                $this->workMode($location),
            ]),
            source: $this->name(),
            publishedAt: $publishedAt,
            company: $board->company ?? $companyName,
            location: $this->optionalText($location['fullLocation'] ?? null),
            externalId: $board->token . ':' . $id,
            publicationDateSource: $publishedAt === null ? PublicationDateSource::Unknown : PublicationDateSource::Api,
            publicationDateConfidence: $publishedAt === null ? PublicationDateConfidence::Unknown : PublicationDateConfidence::Medium,
        );
    }

    /** @param array<mixed>|null $detail */
    private function sections(?array $detail): ?string
    {
        $sections = is_array($detail['jobAd']['sections'] ?? null) ? $detail['jobAd']['sections'] : [];
        $parts = [];
        foreach (['jobDescription', 'qualifications', 'additionalInformation', 'companyDescription'] as $name) {
            $section = $sections[$name] ?? null;
            $text = is_array($section) ? $this->optionalText($section['text'] ?? null) : null;
            if ($text !== null) {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function label(mixed $value): ?string
    {
        return is_array($value) ? $this->optionalText($value['label'] ?? null) : null;
    }

    /** @param array<mixed> $location */
    private function workMode(array $location): ?string
    {
        return match (true) {
            ($location['remote'] ?? false) === true => 'Mode de travail : télétravail possible.',
            ($location['hybrid'] ?? false) === true => 'Mode de travail : hybride.',
            default => null,
        };
    }
}
