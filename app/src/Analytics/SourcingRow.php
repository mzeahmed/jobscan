<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * Ce que rapporte une source d'offres : de la collecte à la candidature.
 *
 * Les doublons probables sont exclus : une offre vue par deux sources n'est comptée que pour celle qui l'a trouvée en premier.
 */
final readonly class SourcingRow
{
    public function __construct(
        public string $source,
        public int $collected,
        public int $compatible,
        public int $notified,
        public int $tracked,
        public int $applied,
    ) {
    }
}
