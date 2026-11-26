<?php

declare(strict_types=1);

namespace App\Matching;

use App\Profile\Model\Skill;
use App\AI\Extraction\JobRequirements;
use App\Profile\Model\CandidateProfile;

/**
 * Compare les compétences d'une annonce à celles que le candidat **déclare** : une compétence absente du
 * profil est « manquante », jamais supposée acquise.
 */
final readonly class SkillComparator
{
    public function __construct(private SkillCanonicalizer $skills)
    {
    }

    public function compare(CandidateProfile $candidate, JobRequirements $requirements): SkillComparison
    {
        $declared = [];
        foreach ($candidate->skills as $skill) {
            $declared[$this->skills->key($skill->name)] ??= $skill;
        }

        [$matchedRequired, $missingRequired] = $this->split($requirements->requiredSkills, $declared);
        [$matchedNice, $missingNice] = $this->split([...$requirements->niceSkills, ...$requirements->mentionedSkills], $declared);

        return new SkillComparison($matchedRequired, $missingRequired, $matchedNice, $missingNice);
    }

    /**
     * @param list<string> $jobSkills
     * @param array<string, Skill> $declared
     * @return array{0: list<SkillMatch>, 1: list<string>}
     */
    private function split(array $jobSkills, array $declared): array
    {
        $matched = [];
        $missing = [];
        $seen = [];

        foreach ($jobSkills as $jobSkill) {
            $key = $this->skills->key($jobSkill);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if (isset($declared[$key])) {
                $matched[] = new SkillMatch($jobSkill, $declared[$key]);
            } else {
                $missing[] = $jobSkill;
            }
        }

        return [$matched, $missing];
    }
}
