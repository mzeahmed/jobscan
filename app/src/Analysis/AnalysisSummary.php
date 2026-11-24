<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * Bilan d'une reprise d'analyses. `skipped` : offres laissées inchangées lors d'une ré-extraction
 * (LLM indisponible).
 */
final readonly class AnalysisSummary
{
    public function __construct(
        public int $analyzedByAi = 0,
        public int $analyzedByFallback = 0,
        public int $failed = 0,
        public int $notified = 0,
        public int $skipped = 0,
    ) {
    }

    public function total(): int
    {
        return $this->analyzedByAi + $this->analyzedByFallback + $this->failed;
    }
}
