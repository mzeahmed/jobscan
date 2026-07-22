<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class JobDto
{
    public function __construct(
        public string $title,
        public string $url,
        public string $description,
        public string $source,
        public ?\DateTimeImmutable $publishedAt = null,
    ) {
    }
}
