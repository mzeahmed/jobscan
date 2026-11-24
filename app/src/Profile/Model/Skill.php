<?php

declare(strict_types=1);

namespace App\Profile\Model;

/**
 * Compétence déclarée par le candidat. Rien n'est supposé : ni le niveau ni les années
 * d'expérience ne sont renseignés par défaut.
 */
final readonly class Skill
{
    public function __construct(
        public string $name,
        public ?SkillLevel $level = null,
        public ?float $yearsOfExperience = null,
    ) {
    }

    /** Clé de comparaison insensible à la casse et aux espaces superflus. */
    public function key(): string
    {
        return self::normalize($this->name);
    }

    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
