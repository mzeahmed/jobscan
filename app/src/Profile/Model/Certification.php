<?php

declare(strict_types=1);

namespace App\Profile\Model;

final readonly class Certification
{
    public function __construct(
        public string $name,
        public ?string $issuer,
        public ?int $year,
    ) {
    }
}
