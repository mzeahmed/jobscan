<?php

declare(strict_types=1);

namespace App\Analytics;

use Twig\TwigFunction;
use Twig\Extension\AbstractExtension;

final class AnalyticsExtension extends AbstractExtension
{
    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('rate', Rate::format(...))];
    }
}
