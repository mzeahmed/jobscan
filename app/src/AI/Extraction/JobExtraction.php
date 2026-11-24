<?php

declare(strict_types=1);

namespace App\AI\Extraction;

use App\DTO\AiAnalysisDto;

/**
 * Résultat d'une extraction : les exigences structurées, et la vue historique (`AiAnalysisDto`)
 * qu'utilise encore le scoring existant.
 */
final readonly class JobExtraction
{
    public function __construct(
        public JobRequirements $requirements,
        public AiAnalysisDto $analysis,
        public bool $usedFallback,
    ) {
    }
}
