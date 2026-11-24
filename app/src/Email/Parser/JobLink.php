<?php

declare(strict_types=1);

namespace App\Email\Parser;

/**
 * Lien d'offre reconnu : URL **normalisée** (sans suivi) et identifiant stable de la plateforme.
 */
final readonly class JobLink
{
    public function __construct(
        public string $url,
        public string $externalId,
    ) {
    }
}
