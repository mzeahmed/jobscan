<?php

declare(strict_types=1);

namespace App\AI\Extraction;

use App\Profile\Model\SalaryPeriod;

/**
 * Rémunération annoncée, telle qu'écrite dans l'annonce : jamais convertie ni estimée. Au moins une
 * borne est renseignée.
 */
final readonly class SalaryRange
{
    public function __construct(
        public ?int $min,
        public ?int $max,
        public string $currency,
        public SalaryPeriod $period,
    ) {
    }

    /** @return array{min: ?int, max: ?int, currency: string, period: string} */
    public function toArray(): array
    {
        return ['min' => $this->min, 'max' => $this->max, 'currency' => $this->currency, 'period' => $this->period->value];
    }
}
