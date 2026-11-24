<?php

declare(strict_types=1);

namespace App\Analytics;

use Twig\TwigFunction;
use Twig\Extension\AbstractExtension;

final class AnalyticsExtension extends AbstractExtension
{
    /** Pourcentage entier, ou `null` si l'échantillon est trop petit pour en afficher un. */
    public static function percent(int $part, int $total): ?int
    {
        $rate = Rate::of($part, $total);

        return $rate === null ? null : (int) round($rate * 100);
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('rate', Rate::format(...)), new TwigFunction('rate_percent', self::percent(...))];
    }
}
