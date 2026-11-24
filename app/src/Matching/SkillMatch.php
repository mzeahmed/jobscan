<?php

declare(strict_types=1);

namespace App\Matching;

use App\Profile\Model\Skill;

/**
 * Une compétence de l'annonce que le candidat déclare, avec la compétence déclarée correspondante.
 */
final readonly class SkillMatch
{
    public function __construct(
        public string $jobSkill,
        public Skill $declared,
    ) {
    }
}
