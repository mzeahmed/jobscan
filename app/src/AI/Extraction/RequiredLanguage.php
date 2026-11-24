<?php

declare(strict_types=1);

namespace App\AI\Extraction;

final readonly class RequiredLanguage
{
    /**
     * @param string|null $level Niveau tel qu'écrit dans l'annonce (courant, B2, bilingue…)
     * @param bool $required Vrai si l'annonce l'exige, faux si elle l'apprécie seulement
     */
    public function __construct(
        public string $name,
        public ?string $level,
        public bool $required,
    ) {
    }

    /** @return array{name: string, level: ?string, required: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'level' => $this->level, 'required' => $this->required];
    }
}
