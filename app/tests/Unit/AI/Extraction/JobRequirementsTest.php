<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Extraction;

use App\DTO\Seniority;
use App\DTO\ContractType;
use PHPUnit\Framework\TestCase;
use App\AI\Extraction\SalaryRange;
use App\Profile\Model\SalaryPeriod;
use App\AI\Extraction\JobRequirements;
use App\AI\Extraction\ExtractionSource;
use App\AI\Extraction\RequiredLanguage;
use App\Profile\Model\RemotePreference;

final class JobRequirementsTest extends TestCase
{
    public function testSurvivesAJsonRoundTrip(): void
    {
        $requirements = new JobRequirements(
            requiredSkills: ['PHP'],
            niceSkills: ['Docker'],
            mentionedSkills: ['Redis'],
            seniority: Seniority::Senior,
            minYearsExperience: 5,
            languages: [new RequiredLanguage('anglais', 'courant', true), new RequiredLanguage('allemand', null, false)],
            remoteMode: RemotePreference::Hybrid,
            contractTypes: [ContractType::Cdi, ContractType::Freelance],
            salary: new SalaryRange(45000, null, 'EUR', SalaryPeriod::Annual),
            locations: ['Marseille'],
            source: ExtractionSource::Llm,
            warnings: ['Compétence « X » absente de l\'annonce, écartée.'],
        );

        $restored = JobRequirements::fromArray(json_decode(json_encode($requirements->toArray(), JSON_THROW_ON_ERROR), true));

        self::assertEquals($requirements, $restored);
    }

    public function testTheSchemaVersionIsStored(): void
    {
        self::assertSame(JobRequirements::SCHEMA_VERSION, new JobRequirements()->toArray()['schema_version']);
    }

    public function testAnUnknownOrFutureSchemaVersionIsNotRead(): void
    {
        self::assertNull(JobRequirements::fromArray(['required_skills' => ['PHP']]));
        self::assertNull(JobRequirements::fromArray(['schema_version' => 0]));
        self::assertNull(JobRequirements::fromArray(['schema_version' => JobRequirements::SCHEMA_VERSION + 1]));
        self::assertNull(JobRequirements::fromArray(['schema_version' => '1']));
    }

    public function testReadingIsTolerantToInvalidStoredFields(): void
    {
        $requirements = JobRequirements::fromArray([
            'schema_version' => 1,
            'required_skills' => ['PHP', 42, null, ['x']],
            'seniority' => 'legend',
            'min_years_experience' => 'beaucoup',
            'languages' => ['pas un tableau', ['name' => 'anglais'], ['nom' => 'x']],
            'remote_mode' => 'teleportation',
            'contract_types' => ['cdi', 'unknown', 'portage', 7],
            'salary' => ['min' => 'x', 'period' => 'hourly'],
            'locations' => 'Marseille',
            'source' => 'oracle',
        ]);

        self::assertNotNull($requirements);
        self::assertSame(['PHP'], $requirements->requiredSkills);
        self::assertSame(Seniority::Unknown, $requirements->seniority);
        self::assertNull($requirements->minYearsExperience);
        self::assertCount(1, $requirements->languages);
        self::assertNull($requirements->remoteMode);
        self::assertSame([ContractType::Cdi], $requirements->contractTypes);
        self::assertNull($requirements->salary);
        self::assertSame([], $requirements->locations);
        self::assertSame(ExtractionSource::Llm, $requirements->source);
    }

    public function testWithWarningsKeepsEverythingElse(): void
    {
        $requirements = new JobRequirements(requiredSkills: ['PHP'], warnings: ['a']);

        $changed = $requirements->withWarnings(['b']);

        self::assertSame(['a', 'b'], $changed->warnings);
        self::assertSame(['PHP'], $changed->requiredSkills);
        self::assertSame(['a'], $requirements->warnings, 'Immuable.');
    }

    public function testAllSkillsListsTheMostDemandingFirst(): void
    {
        $requirements = new JobRequirements(requiredSkills: ['A'], niceSkills: ['B'], mentionedSkills: ['C']);

        self::assertSame(['A', 'B', 'C'], $requirements->allSkills());
    }
}
