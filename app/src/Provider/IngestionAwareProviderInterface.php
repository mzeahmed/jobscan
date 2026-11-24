<?php

declare(strict_types=1);

namespace App\Provider;

use App\DTO\JobDto;
use App\Processor\JobProcessingResult;

/**
 * Provider qui doit savoir si ses offres ont réellement été ingérées avant de considérer
 * sa source comme traitée (ex. email : traité seulement après ingestion réussie).
 *
 * Appelé par le pipeline après `processBatch`, sauf en dry-run.
 */
interface IngestionAwareProviderInterface
{
    /**
     * @param list<JobDto> $jobs Offres retournées par `fetch()`, dans le même ordre
     * @param list<JobProcessingResult> $results Résultats d'ingestion alignés sur `$jobs`
     */
    public function acknowledge(array $jobs, array $results): void;
}
