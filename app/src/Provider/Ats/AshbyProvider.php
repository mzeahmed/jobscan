<?php

declare(strict_types=1);

namespace App\Provider\Ats;

use App\DTO\JobDto;
use App\DTO\PublicationDateSource;
use App\DTO\PublicationDateConfidence;

/**
 * Offres d'un job board Ashby (API publique de publication d'offres).
 *
 * `GET https://api.ashbyhq.com/posting-api/job-board/{nom}?includeCompensation=true` — documentation :
 * https://developers.ashbyhq.com/docs/public-job-posting-api (vérifiée). Pas de pagination.
 *
 * Limites de la documentation : aucun identifiant par offre (l'identité d'une offre est donc son
 * URL), authentification, quotas et conditions non précisés. `publishedAt` est documenté comme la
 * date de dernière publication, pas de première publication : confiance moyenne. Les offres non
 * listées (`isListed: false`), réservées à un accès par lien direct, sont ignorées.
 */
final class AshbyProvider extends AbstractAtsProvider
{
    private const string BASE_URL = 'https://api.ashbyhq.com/posting-api/job-board/';

    public function name(): string
    {
        return 'ashby';
    }

    protected function probeUrl(AtsBoard $board): string
    {
        return self::BASE_URL . $board->token;
    }

    protected function fetchBoard(AtsBoard $board): ?array
    {
        $data = $this->getJson($board, self::BASE_URL . $board->token, ['includeCompensation' => 'true']);
        if ($data === null) {
            return null;
        }

        $jobs = [];
        foreach ((array) ($data['jobs'] ?? []) as $offer) {
            $job = is_array($offer) ? $this->toJob($board, $offer) : null;
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * @param array<mixed> $offer
     */
    private function toJob(AtsBoard $board, array $offer): ?JobDto
    {
        $title = $this->title($offer['title'] ?? null);
        $url = $offer['jobUrl'] ?? null;
        if ($title === null || !$this->isHttpUrl($url) || ($offer['isListed'] ?? true) === false) {
            return null;
        }

        $publishedAt = $this->parseDate($offer['publishedAt'] ?? null);
        $others = array_filter(array_map(
            fn (mixed $secondary): ?string => is_array($secondary) ? $this->optionalText($secondary['location'] ?? null) : null,
            (array) ($offer['secondaryLocations'] ?? []),
        ));
        $compensation = is_array($offer['compensation'] ?? null) ? $this->optionalText($offer['compensation']['compensationTierSummary'] ?? null) : null;

        return new JobDto(
            title: $title,
            url: (string) $url,
            description: $this->description([
                $this->plainText($offer['descriptionPlain'] ?? $offer['descriptionHtml'] ?? null),
                $this->labelled('Contrat', $offer['employmentType'] ?? null),
                $this->labelled('Mode de travail', $offer['workplaceType'] ?? null),
                $this->labelled('Département', $offer['department'] ?? null),
                $this->labelled('Équipe', $offer['team'] ?? null),
                $others === [] ? null : 'Autres lieux : ' . implode(', ', $others) . '.',
                $compensation === null ? null : 'Rémunération : ' . $compensation,
            ]),
            source: $this->name(),
            publishedAt: $publishedAt,
            company: $board->company,
            location: $this->optionalText($offer['location'] ?? null),
            publicationDateSource: $publishedAt === null ? PublicationDateSource::Unknown : PublicationDateSource::Api,
            publicationDateConfidence: $publishedAt === null ? PublicationDateConfidence::Unknown : PublicationDateConfidence::Medium,
        );
    }

    private function labelled(string $label, mixed $value): ?string
    {
        $text = $this->optionalText($value);

        return $text === null ? null : sprintf('%s : %s.', $label, $text);
    }
}
