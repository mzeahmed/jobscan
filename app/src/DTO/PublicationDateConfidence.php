<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Niveau de confiance accordé à la date de publication d'une offre.
 *
 * `rank()` permet de comparer deux observations d'une même offre : la plus fiable
 * l'emporte lors d'une fusion.
 */
enum PublicationDateConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Unknown = 'unknown';

    public function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
            self::Unknown => 0,
        };
    }
}
