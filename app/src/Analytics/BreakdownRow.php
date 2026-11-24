<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * Candidatures envoyées pour une valeur d'une dimension (source, CV, profil de recherche, canal).
 */
final readonly class BreakdownRow
{
    public function __construct(
        public string $label,
        public int $sent,
        public int $responded,
        public int $interviews,
        public int $offers,
    ) {
    }
}
