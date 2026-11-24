<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Extraction;

use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use PHPUnit\Framework\TestCase;
use App\AI\Extraction\SalaryRange;
use App\Profile\Model\SalaryPeriod;
use App\AI\Extraction\JobRequirements;
use App\AI\Extraction\ExtractionSource;
use App\Profile\Model\RemotePreference;
use App\AI\Extraction\LegacyAnalysisMapper;
use PHPUnit\Framework\Attributes\DataProvider;

final class LegacyAnalysisMapperTest extends TestCase
{
    public function testTheStackIsTheUniqueLowercasedUnionOfAllSkills(): void
    {
        $analysis = LegacyAnalysisMapper::toAnalysis(new JobRequirements(
            requiredSkills: ['PHP', 'Symfony'],
            niceSkills: ['Docker'],
            mentionedSkills: ['php', 'Redis'],
        ));

        self::assertSame(['php', 'symfony', 'docker', 'redis'], $analysis->stack);
    }

    /** @return iterable<string, array{list<ContractType>, ContractType, bool}> */
    public static function contracts(): iterable
    {
        yield 'aucun' => [[], ContractType::Unknown, false];
        yield 'freelance' => [[ContractType::Freelance], ContractType::Freelance, true];
        yield 'cdi' => [[ContractType::Cdi], ContractType::Cdi, false];
        yield 'freelance prioritaire' => [[ContractType::Cdi, ContractType::Freelance], ContractType::Freelance, true];
        yield 'cdi avant cdd' => [[ContractType::Cdd, ContractType::Cdi], ContractType::Cdi, false];
        yield 'cdd seul' => [[ContractType::Cdd], ContractType::Cdd, false];
        yield 'stage' => [[ContractType::Internship], ContractType::Internship, false];
    }

    /** @param list<ContractType> $types */
    #[DataProvider('contracts')]
    public function testContractMapping(array $types, ContractType $expected, bool $freelance): void
    {
        $analysis = LegacyAnalysisMapper::toAnalysis(new JobRequirements(contractTypes: $types));

        self::assertSame($expected, $analysis->contractType);
        self::assertSame($freelance, $analysis->freelance);
    }

    /** @return iterable<string, array{?RemotePreference, bool}> */
    public static function remoteModes(): iterable
    {
        yield 'remote' => [RemotePreference::Remote, true];
        yield 'hybride (télétravail mentionné)' => [RemotePreference::Hybrid, true];
        yield 'sur site' => [RemotePreference::Onsite, false];
        yield 'inconnu' => [null, false];
    }

    #[DataProvider('remoteModes')]
    public function testRemoteMapping(?RemotePreference $mode, bool $expected): void
    {
        self::assertSame($expected, LegacyAnalysisMapper::toAnalysis(new JobRequirements(remoteMode: $mode))->remote);
    }

    /** @return iterable<string, array{?SalaryRange, string}> */
    public static function budgets(): iterable
    {
        yield 'aucun' => [null, 'non précisé'];
        yield 'TJM' => [new SalaryRange(500, 600, 'EUR', SalaryPeriod::Daily), '600€/j'];
        yield 'TJM minimum seul' => [new SalaryRange(550, null, 'EUR', SalaryPeriod::Daily), '550€/j'];
        yield 'annuel fourchette' => [new SalaryRange(45000, 55000, 'EUR', SalaryPeriod::Annual), '45-55k€/an'];
        yield 'annuel arrondi' => [new SalaryRange(42500, 52500, 'EUR', SalaryPeriod::Annual), '43-53k€/an'];
        yield 'annuel un seul montant' => [new SalaryRange(60000, null, 'EUR', SalaryPeriod::Annual), '60k€/an'];
        yield 'annuel bornes égales' => [new SalaryRange(50000, 50000, 'EUR', SalaryPeriod::Annual), '50k€/an'];
        yield 'autre devise : jamais convertie' => [new SalaryRange(80000, 100000, 'USD', SalaryPeriod::Annual), 'non précisé'];
        yield 'TJM en livres' => [new SalaryRange(500, 500, 'GBP', SalaryPeriod::Daily), 'non précisé'];
    }

    #[DataProvider('budgets')]
    public function testBudgetUsesTheFormatTheScoringReads(?SalaryRange $salary, string $expected): void
    {
        self::assertSame($expected, LegacyAnalysisMapper::toAnalysis(new JobRequirements(salary: $salary))->budget);
    }

    public function testSeniorityAndRecencyAreCarriedOver(): void
    {
        $analysis = LegacyAnalysisMapper::toAnalysis(new JobRequirements(seniority: Seniority::Senior), true);

        self::assertSame(Seniority::Senior, $analysis->seniority);
        self::assertTrue($analysis->recent);
    }

    public function testHeuristicRequirementsOnlyMentionSkillsNeverRequireThem(): void
    {
        $requirements = LegacyAnalysisMapper::toRequirements(new AiAnalysisDto(
            ['php', 'symfony'],
            ContractType::Freelance,
            true,
            true,
            '500€/j',
            false,
            Seniority::Mid,
        ));

        self::assertSame(ExtractionSource::Heuristic, $requirements->source);
        self::assertSame([], $requirements->requiredSkills, 'L\'heuristique ne sait pas ce qui est exigé.');
        self::assertSame([], $requirements->niceSkills);
        self::assertSame(['php', 'symfony'], $requirements->mentionedSkills);
        self::assertSame([ContractType::Freelance], $requirements->contractTypes);
        self::assertSame(RemotePreference::Remote, $requirements->remoteMode);
        self::assertSame(Seniority::Mid, $requirements->seniority);
        self::assertNull($requirements->salary);
    }

    public function testHeuristicRequirementsLeaveUnknownThingsUnknown(): void
    {
        $requirements = LegacyAnalysisMapper::toRequirements(new AiAnalysisDto([], ContractType::Unknown, false, false, 'non précisé', false, Seniority::Unknown));

        self::assertSame([], $requirements->contractTypes);
        self::assertNull($requirements->remoteMode, 'Pas de télétravail détecté ne veut pas dire « sur site ».');
    }
}
