<?php

declare(strict_types=1);

namespace App\Profile\Model;

final readonly class Experience
{
    /**
     * @param string $startDate Mois de début (`AAAA-MM`)
     * @param string|null $endDate Mois de fin (`AAAA-MM`), null si l'expérience est en cours
     * @param list<string> $achievements Réalisations concrètes, telles que saisies (jamais complétées)
     * @param list<string> $skills Compétences mobilisées
     */
    public function __construct(
        public string $company,
        public string $title,
        public string $startDate,
        public ?string $endDate,
        public ?string $summary,
        public array $achievements,
        public array $skills,
    ) {
    }
}
