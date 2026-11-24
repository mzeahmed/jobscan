<?php

declare(strict_types=1);

namespace App\Profile\Model;

final readonly class Project
{
    /**
     * @param list<string> $skills
     * @param list<string> $achievements
     */
    public function __construct(
        public string $name,
        public ?string $summary,
        public ?string $url,
        public array $skills,
        public array $achievements,
    ) {
    }
}
