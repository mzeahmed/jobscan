<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * Un pourcentage n'est affiché que sur un échantillon assez grand : « 1 réponse sur 2 » ne vaut pas « 50 % ».
 */
final class Rate
{
    /** Effectif minimal pour afficher un pourcentage. */
    public const int MIN_SAMPLE = 5;

    public static function of(int $part, int $total): ?float
    {
        return $total >= self::MIN_SAMPLE ? $part / $total : null;
    }

    public static function format(int $part, int $total): string
    {
        $rate = self::of($part, $total);

        return $rate === null ? sprintf('%d/%d', $part, $total) : sprintf('%d/%d (%d %%)', $part, $total, (int) round($rate * 100));
    }
}
