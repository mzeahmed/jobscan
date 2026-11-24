<?php

declare(strict_types=1);

namespace App\Tests\Unit\Matching;

use App\DTO\Seniority;
use App\DTO\ContractType;
use App\Matching\JobFacts;
use App\Profile\Model\Skill;
use App\Profile\Model\Language;
use PHPUnit\Framework\TestCase;
use App\Profile\Model\CvVersion;
use App\Profile\Model\Experience;
use App\Profile\Model\SkillLevel;
use App\Profile\Model\SearchProfile;
use App\Profile\Model\ScoringWeights;
use App\AI\Extraction\JobRequirements;
use App\Profile\Model\CandidateProfile;
use App\Profile\Model\RemotePreference;
use App\Profile\Model\SalaryExpectation;
use App\Profile\Model\LocationPreferences;

/**
 * Fabriques de profils, d'annonces et d'exigences pour tester le matching.
 */
abstract class MatchingTestCase extends TestCase
{
    protected const array DEFAULT_WEIGHTS = [
        'required_skills' => 40, 'nice_skills' => 15, 'title' => 15, 'location' => 10, 'remote' => 5,
        'contract' => 5, 'salary' => 5, 'seniority' => 3, 'languages' => 1, 'availability' => 1,
    ];

    protected \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-30 12:00:00');
    }

    /**
     * @param list<string|Skill> $skills
     * @param list<string> $excludedSkills
     * @param list<Experience> $experiences
     * @param list<string> $cities
     * @param list<RemotePreference> $remoteModes
     * @param list<ContractType> $contracts
     * @param list<SalaryExpectation> $salary
     * @param list<Language> $languages
     * @param list<string> $titles
     * @param list<CvVersion> $cvs
     */
    protected static function candidate(
        array $skills = ['PHP', 'Symfony', 'Docker'],
        array $excludedSkills = [],
        array $experiences = [],
        array $cities = [],
        array $remoteModes = [],
        array $contracts = [],
        array $salary = [],
        array $languages = [],
        array $titles = [],
        array $cvs = [],
    ): CandidateProfile {
        return new CandidateProfile(
            fullName: null,
            headline: null,
            email: null,
            phone: null,
            targetTitles: $titles,
            skills: array_map(static fn (string | Skill $skill): Skill => is_string($skill) ? new Skill($skill) : $skill, $skills),
            excludedSkills: $excludedSkills,
            experiences: $experiences,
            projects: [],
            achievements: [],
            certifications: [],
            languages: $languages,
            location: new LocationPreferences($cities, null, null),
            remoteModes: $remoteModes,
            contracts: $contracts,
            salaryExpectations: $salary,
            availability: null,
            cvVersions: $cvs,
        );
    }

    /**
     * @param list<string> $keywords
     * @param list<string> $titles
     * @param list<string> $primary
     * @param list<string> $secondary
     * @param list<string> $locations
     * @param list<ContractType> $contracts
     * @param list<RemotePreference> $remoteModes
     * @param list<string> $excludedKeywords
     * @param list<string> $excludedTitles
     * @param list<string> $excludedSkills
     * @param array<string, int> $weights
     */
    protected static function profile(
        string $id = 'backend',
        array $keywords = [],
        array $titles = [],
        array $primary = [],
        array $secondary = [],
        array $locations = [],
        array $contracts = [],
        array $remoteModes = [],
        array $excludedKeywords = [],
        array $excludedTitles = [],
        array $excludedSkills = [],
        int $minScore = 60,
        array $weights = [],
        ?string $cv = null,
    ): SearchProfile {
        return new SearchProfile(
            $id,
            $id,
            true,
            $keywords,
            $titles,
            $primary,
            $secondary,
            $locations,
            $contracts,
            $remoteModes,
            $excludedKeywords,
            $excludedTitles,
            $excludedSkills,
            $minScore,
            ScoringWeights::create(self::DEFAULT_WEIGHTS, $weights),
            $cv,
        );
    }

    protected static function facts(JobRequirements $requirements = new JobRequirements(), string $title = 'Développeur PHP', string $description = 'Poste de développeur.', ?string $location = null): JobFacts
    {
        return new JobFacts($title, $description, $location, $requirements);
    }

    protected static function experience(string $company = 'Exemple SAS', string $title = 'Développeur', string $start = '2018-01', ?string $end = null, string ...$skills): Experience
    {
        return new Experience($company, $title, $start, $end, null, [], array_values($skills));
    }

    protected static function skillLevel(string $name, SkillLevel $level, ?float $years = null): Skill
    {
        return new Skill($name, $level, $years);
    }

    /** @param list<string> $required @param list<string> $nice */
    protected static function requirements(array $required = [], array $nice = [], Seniority $seniority = Seniority::Unknown, ?int $years = null): JobRequirements
    {
        return new JobRequirements(requiredSkills: $required, niceSkills: $nice, seniority: $seniority, minYearsExperience: $years);
    }
}
