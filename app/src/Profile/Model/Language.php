<?php

declare(strict_types=1);

namespace App\Profile\Model;

final readonly class Language
{
    /** @param string|null $level Niveau tel que saisi (A2, C1, natif, courant…) */
    public function __construct(
        public string $name,
        public ?string $level,
    ) {
    }
}
