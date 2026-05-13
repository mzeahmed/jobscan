<?php

declare(strict_types=1);

namespace App\DTO;

final class JobDTO
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $description,
        public readonly string $source,
        public readonly ?\DateTimeImmutable $publishedAt = null,
    ) {
    }
}
