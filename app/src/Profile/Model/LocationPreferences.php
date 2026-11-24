<?php

declare(strict_types=1);

namespace App\Profile\Model;

final readonly class LocationPreferences
{
    /** @param list<string> $cities Lieux de travail souhaités */
    public function __construct(
        public array $cities,
        public ?Mobility $mobility,
        public ?int $maxDistanceKm,
    ) {
    }
}
