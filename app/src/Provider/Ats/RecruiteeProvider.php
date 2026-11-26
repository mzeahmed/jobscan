<?php

declare(strict_types=1);

namespace App\Provider\Ats;

use App\DTO\JobDto;
use App\DTO\PublicationDateSource;
use App\DTO\PublicationDateConfidence;

/**
 * Offres d'un site carrières Recruitee (API publique du site carrières, sans authentification).
 *
 * `GET https://{identifiant}.recruitee.com/api/offers/` — forme de la réponse **observée sur un site réel** lors de
 * l'écriture (champs `offers[]` : `id`, `title`, `description`, `requirements`, `careers_url`, `published_at`,
 * `company_name`, `location`, `remote`, `hybrid`, `on_site`, `employment_type_code`, `salary`…). Cette API n'est pas
 * décrite dans une documentation d'éditeur que j'aie pu lire : ses champs peuvent évoluer.
 *
 * L'identifiant est un sous-domaine : il doit être une étiquette DNS valide, pour que l'hôte reste `*.recruitee.com`.
 * `published_at` est traité comme une date de publication de confiance moyenne (une republication n'est pas distinguable).
 */
final class RecruiteeProvider extends AbstractAtsProvider
{
    public function name(): string
    {
        return 'recruitee';
    }

    protected function probeUrl(AtsBoard $board): string
    {
        return $this->url($board);
    }

    protected function fetchBoard(AtsBoard $board): ?array
    {
        if (!$this->isHostLabel($board->token)) {
            $this->fail($board, 'identifiant invalide pour Recruitee (sous-domaine : lettres, chiffres et tirets)');

            return null;
        }

        $data = $this->getJson($board, $this->url($board));
        if ($data === null) {
            return null;
        }

        $jobs = [];
        foreach ((array) ($data['offers'] ?? []) as $offer) {
            $job = is_array($offer) ? $this->toJob($board, $offer) : null;
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    private function url(AtsBoard $board): string
    {
        // Un identifiant invalide ne produit jamais d'hôte : l'URL pointe alors vers un domaine réservé non résolvable.
        return $this->isHostLabel($board->token)
            ? sprintf('https://%s.recruitee.com/api/offers/', strtolower($board->token))
            : 'https://invalid.invalid/';
    }

    /**
     * @param array<mixed> $offer
     */
    /**
     * @param array<string, mixed> $offer
     */
    private function toJob(AtsBoard $board, array $offer): ?JobDto
    {
        $id = $offer['id'] ?? null;
        $title = $this->title($offer['title'] ?? null);
        $url = $offer['careers_url'] ?? null;
        if (!is_scalar($id) || (string) $id === '' || $title === null || !$this->isHttpUrl($url)) {
            return null;
        }

        if (is_string($offer['status'] ?? null) && $offer['status'] !== 'published') {
            return null;
        }

        $publishedAt = $this->parseDate($offer['published_at'] ?? null);
        $company = $board->company ?? $this->optionalText($offer['company_name'] ?? null);

        return new JobDto(
            title: $title,
            url: (string) $url,
            description: $this->description([
                $this->plainText($offer['description'] ?? null),
                $this->plainText($offer['requirements'] ?? null),
                $this->labelled('Contrat', is_string($offer['employment_type_code'] ?? null) ? str_replace('_', ' ', $offer['employment_type_code']) : null),
                $this->workMode($offer),
                $this->labelled('Département', $offer['department'] ?? null),
                $this->labelled('Niveau d\'expérience', is_string($offer['experience_code'] ?? null) ? str_replace('_', ' ', $offer['experience_code']) : null),
                $this->salary($offer['salary'] ?? null),
            ]),
            source: $this->name(),
            publishedAt: $publishedAt,
            company: $company,
            location: $this->optionalText($offer['location'] ?? null),
            externalId: $board->token . ':' . $id,
            publicationDateSource: $publishedAt === null ? PublicationDateSource::Unknown : PublicationDateSource::Api,
            publicationDateConfidence: $publishedAt === null ? PublicationDateConfidence::Unknown : PublicationDateConfidence::Medium,
        );
    }

    /** @param array<mixed> $offer */
    /**
     * @param array<string, mixed> $offer
     */
    private function workMode(array $offer): ?string
    {
        return match (true) {
            ($offer['remote'] ?? false) === true => 'Mode de travail : télétravail possible.',
            ($offer['hybrid'] ?? false) === true => 'Mode de travail : hybride.',
            ($offer['on_site'] ?? false) === true => 'Mode de travail : sur site.',
            default => null,
        };
    }

    private function salary(mixed $salary): ?string
    {
        if (!is_array($salary)) {
            return null;
        }

        $min = is_numeric($salary['min'] ?? null) ? (int) $salary['min'] : null;
        $max = is_numeric($salary['max'] ?? null) ? (int) $salary['max'] : null;
        if ($min === null && $max === null) {
            return null;
        }

        $amount = $min !== null && $max !== null && $min !== $max ? sprintf('%d–%d', $min, $max) : (string) ($min ?? $max);
        $currency = $this->optionalText($salary['currency'] ?? null);
        $period = $this->optionalText($salary['period'] ?? null);

        return sprintf('Rémunération : %s%s%s.', $amount, $currency === null ? '' : ' ' . $currency, $period === null ? '' : ' / ' . $period);
    }
}
