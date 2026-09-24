<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Critères validés du listing des offres.
 */
final readonly class JobListingFilters
{
    public function __construct(
        public ?string $location = null,
        public ?bool $remote = null,
        public ?ContractType $contractType = null,
        public ?Seniority $seniority = null,
        public ?bool $freelance = null,
        public ?int $minimumScore = null,
        public ?string $source = null,
        public ?int $ageInDays = null,
        public ?bool $notified = null,
        public string $sort = 'date',
    ) {
    }
}
