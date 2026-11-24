<?php

declare(strict_types=1);

namespace App\Provider\Ats;

use App\DTO\JobDto;

/**
 * Offres d'un job board Greenhouse (Job Board API, publique, sans authentification).
 *
 * `GET https://boards-api.greenhouse.io/v1/boards/{token}/jobs?content=true` — documentation :
 * https://developers.greenhouse.io/job-board.html (vérifiée). Pas de pagination, la liste contient
 * toutes les offres du board.
 *
 * La liste ne fournit que `updated_at` (dernière mise à jour), jamais `first_published` : ce n'est
 * pas une date de publication, donc la date de publication reste **inconnue**. L'entreprise est
 * celle saisie dans la configuration (`token=Nom`), l'API ne la renvoie pas dans la liste.
 */
final class GreenhouseProvider extends AbstractAtsProvider
{
    private const string BASE_URL = 'https://boards-api.greenhouse.io/v1/boards/';

    public function name(): string
    {
        return 'greenhouse';
    }

    protected function probeUrl(AtsBoard $board): string
    {
        return self::BASE_URL . $board->token;
    }

    protected function fetchBoard(AtsBoard $board): ?array
    {
        $data = $this->getJson($board, self::BASE_URL . $board->token . '/jobs', ['content' => 'true']);
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
        $id = $offer['id'] ?? null;
        $title = $this->title($offer['title'] ?? null);
        $url = $offer['absolute_url'] ?? null;
        if (!is_scalar($id) || (string) $id === '' || $title === null || !$this->isHttpUrl($url)) {
            return null;
        }

        $location = is_array($offer['location'] ?? null) ? $this->optionalText($offer['location']['name'] ?? null) : null;
        $departments = array_filter(array_map(
            fn (mixed $department): ?string => is_array($department) ? $this->optionalText($department['name'] ?? null) : null,
            (array) ($offer['departments'] ?? []),
        ));

        return new JobDto(
            title: $title,
            url: (string) $url,
            description: $this->description([
                $this->plainText($offer['content'] ?? null),
                $departments === [] ? null : 'Département : ' . implode(', ', $departments) . '.',
            ]),
            source: $this->name(),
            company: $board->company,
            location: $location,
            externalId: $board->token . ':' . $id,
        );
    }
}
