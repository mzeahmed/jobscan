<?php

declare(strict_types=1);

namespace App\Matching;

/**
 * Compétences de l'annonce couvertes ou non par le candidat. Les compétences appréciées incluent celles
 * simplement citées sans niveau d'exigence.
 */
final readonly class SkillComparison
{
    /**
     * @param list<SkillMatch> $matchedRequired
     * @param list<string> $missingRequired
     * @param list<SkillMatch> $matchedNice
     * @param list<string> $missingNice
     */
    public function __construct(
        public array $matchedRequired,
        public array $missingRequired,
        public array $matchedNice,
        public array $missingNice,
    ) {
    }

    /** @return list<string> */
    public function matchedSkills(): array
    {
        return array_map(static fn (SkillMatch $match): string => $match->jobSkill, [...$this->matchedRequired, ...$this->matchedNice]);
    }
}
