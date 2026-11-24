<?php

declare(strict_types=1);

namespace App\Matching;

/**
 * Issue du matching d'une offre avec un profil de recherche.
 */
enum MatchVerdict: string
{
    /** Compatible : au-dessus du seuil du profil, avec assez d'informations. */
    case Eligible = 'eligible';
    /** Évaluée mais sous le seuil minimal de compatibilité du profil. */
    case BelowThreshold = 'below_threshold';
    /** Trop d'informations inconnues pour conclure : à vérifier, pas écartée. */
    case InsufficientData = 'insufficient_data';
    /** Écartée par un critère éliminatoire, quel que soit son score. */
    case Eliminated = 'eliminated';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'compatible',
            self::BelowThreshold => 'sous le seuil',
            self::InsufficientData => 'données insuffisantes',
            self::Eliminated => 'éliminée',
        };
    }
}
