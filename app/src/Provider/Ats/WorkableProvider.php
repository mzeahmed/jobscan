<?php

declare(strict_types=1);

namespace App\Provider\Ats;

use App\DTO\JobDto;
use App\DTO\PublicationDateSource;
use App\DTO\PublicationDateConfidence;

/**
 * Offres d'un compte Workable (API « widget » publique, sans authentification).
 *
 * `GET https://apply.workable.com/api/v1/widget/accounts/{compte}?details=true` — forme de la réponse **observée sur un
 * compte réel** lors de l'écriture (`name`, `jobs[]` : `title`, `shortcode`, `url`, `published_on`, `employment_type`,
 * `telecommuting`, `department`, `city`, `state`, `country`, `description`…). Cette API n'est pas décrite dans une documentation
 * d'éditeur que j'aie pu lire : ses champs peuvent évoluer. Un compte sans offre renvoie `jobs: []`.
 *
 * `published_on` est une date sans heure : date de publication de confiance moyenne. `telecommuting: false` ne signifie pas
 * « sur site » (valeur par défaut) : seul `true` est repris.
 */
final class WorkableProvider extends AbstractAtsProvider
{
    private const string BASE_URL = 'https://apply.workable.com/api/v1/widget/accounts/';

    public function name(): string
    {
        return 'workable';
    }

    protected function probeUrl(AtsBoard $board): string
    {
        return self::BASE_URL . $board->token;
    }

    protected function fetchBoard(AtsBoard $board): ?array
    {
        $data = $this->getJson($board, self::BASE_URL . $board->token, ['details' => 'true']);
        if ($data === null) {
            return null;
        }

        $company = $board->company ?? $this->optionalText($data['name'] ?? null);
        $jobs = [];
        foreach ((array) ($data['jobs'] ?? []) as $offer) {
            $job = is_array($offer) ? $this->toJob($board, $offer, $company) : null;
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * @param array<mixed> $offer
     */
    private function toJob(AtsBoard $board, array $offer, ?string $company): ?JobDto
    {
        $id = $offer['shortcode'] ?? null;
        $title = $this->title($offer['title'] ?? null);
        $url = $offer['url'] ?? $offer['shortlink'] ?? null;
        if (!is_scalar($id) || (string) $id === '' || $title === null || !$this->isHttpUrl($url)) {
            return null;
        }

        $publishedAt = $this->parseDate($offer['published_on'] ?? null);
        $place = array_filter([
            $this->optionalText($offer['city'] ?? null),
            $this->optionalText($offer['state'] ?? null),
            $this->optionalText($offer['country'] ?? null),
        ]);

        return new JobDto(
            title: $title,
            url: (string) $url,
            description: $this->description([
                $this->plainText($offer['description'] ?? null),
                $this->labelled('Contrat', $offer['employment_type'] ?? null),
                ($offer['telecommuting'] ?? false) === true ? 'Mode de travail : télétravail possible.' : null,
                $this->labelled('Département', $offer['department'] ?? null),
                $this->labelled('Fonction', $offer['function'] ?? null),
                $this->labelled('Niveau d\'expérience', $offer['experience'] ?? null),
            ]),
            source: $this->name(),
            publishedAt: $publishedAt,
            company: $company,
            location: $place === [] ? null : implode(', ', $place),
            externalId: $board->token . ':' . $id,
            publicationDateSource: $publishedAt === null ? PublicationDateSource::Unknown : PublicationDateSource::Api,
            publicationDateConfidence: $publishedAt === null ? PublicationDateConfidence::Unknown : PublicationDateConfidence::Medium,
        );
    }
}
