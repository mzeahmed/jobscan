<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\DTO\JobDTO;
use App\Service\AI\PerplexityClient;

/**
 * Fournisseur Perplexity.
 *
 * Utilise le moteur de recherche IA de Perplexity pour trouver des offres
 * récentes sans avoir à scraper manuellement des job boards.
 */
final class PerplexityProvider implements JobProviderInterface
{
    public function __construct(
        private readonly PerplexityClient $perplexityClient,
    ) {
    }

    public function fetch(): array
    {
        $results = $this->perplexityClient->searchJobs();

        return array_map(
            fn (array $item) => new JobDTO(
                title: $item['title'] ?? 'Sans titre',
                url: $item['url'] ?? '',
                description: $item['description'] ?? '',
                source: 'perplexity',
            ),
            array_filter($results, fn (array $item) => !empty($item['url'])),
        );
    }
}
