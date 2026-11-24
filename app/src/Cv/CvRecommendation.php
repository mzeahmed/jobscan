<?php

declare(strict_types=1);

namespace App\Cv;

use App\Profile\Model\CvVersion;

/**
 * Version de CV recommandée pour une offre, et pourquoi. Jamais devinée : elle vient du profil de recherche le plus pertinent.
 */
final readonly class CvRecommendation
{
    public function __construct(
        public ?CvVersion $cv,
        public ?string $searchProfileId,
        public int $priorityScore,
        public string $reason,
    ) {
    }
}
