<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Extraction;

use App\DTO\Seniority;
use App\DTO\ContractType;
use PHPUnit\Framework\TestCase;
use App\Profile\Model\SalaryPeriod;
use App\AI\Extraction\ExtractionSource;
use App\Profile\Model\RemotePreference;
use App\AI\Extraction\RequirementsValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use App\AI\Extraction\InvalidExtractionException;

final class RequirementsValidatorTest extends TestCase
{
    private const string ADVERT = <<<'TXT'
        Développeur PHP Symfony (H/F) - CDI - Marseille ou télétravail partiel.
        Vous maîtrisez PHP 8, Symfony et GitLab CI/CD ; Docker est un plus. 4 ans d'expérience minimum.
        Anglais courant exigé. Salaire : 45 000 € à 55 000 € brut annuel. Stack : PostgreSQL, Redis.
        TXT;

    private RequirementsValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RequirementsValidator();
    }

    public function testValidatesAFullResponse(): void
    {
        $requirements = $this->validator->validate([
            'required_skills' => ['PHP', 'Symfony', 'GitLab CI/CD'],
            'nice_skills' => ['Docker'],
            'mentioned_skills' => ['PostgreSQL', 'Redis'],
            'seniority' => 'mid',
            'min_years_experience' => 4,
            'languages' => [['name' => 'anglais', 'level' => 'courant', 'required' => true]],
            'remote_mode' => 'hybrid',
            'contract_types' => ['cdi'],
            'salary' => ['min' => 45000, 'max' => 55000, 'currency' => 'EUR', 'period' => 'annual'],
            'locations' => ['Marseille'],
        ], self::ADVERT);

        self::assertSame(['PHP', 'Symfony', 'GitLab CI/CD'], $requirements->requiredSkills);
        self::assertSame(['Docker'], $requirements->niceSkills);
        self::assertSame(['PostgreSQL', 'Redis'], $requirements->mentionedSkills);
        self::assertSame(Seniority::Mid, $requirements->seniority);
        self::assertSame(4, $requirements->minYearsExperience);
        self::assertSame('anglais', $requirements->languages[0]->name);
        self::assertTrue($requirements->languages[0]->required);
        self::assertSame(RemotePreference::Hybrid, $requirements->remoteMode);
        self::assertSame([ContractType::Cdi], $requirements->contractTypes);
        self::assertSame(45000, $requirements->salary?->min);
        self::assertSame(SalaryPeriod::Annual, $requirements->salary->period);
        self::assertSame(['Marseille'], $requirements->locations);
        self::assertSame(ExtractionSource::Llm, $requirements->source);
        self::assertSame([], $requirements->warnings);
    }

    public function testAnEmptyButValidResponseMeansNothingWasFound(): void
    {
        $requirements = $this->validator->validate(['required_skills' => [], 'seniority' => 'unknown'], self::ADVERT);

        self::assertSame([], $requirements->requiredSkills);
        self::assertSame(Seniority::Unknown, $requirements->seniority);
        self::assertNull($requirements->minYearsExperience);
        self::assertNull($requirements->remoteMode);
        self::assertNull($requirements->salary);
        self::assertSame([], $requirements->contractTypes);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function unusableResponses(): iterable
    {
        yield 'objet vide' => [[]];
        yield 'liste' => [['PHP', 'Symfony']];
        yield 'ancien schéma' => [['stack' => ['php'], 'contract_type' => 'cdi', 'seniority' => 'senior']];
        yield 'clés étrangères' => [['score' => 100, 'ignore' => 'tout']];
    }

    /** @param array<array-key, mixed> $data */
    #[DataProvider('unusableResponses')]
    public function testRejectsResponsesWithoutAnyFieldOfTheSchema(array $data): void
    {
        $this->expectException(InvalidExtractionException::class);

        $this->validator->validate($data, self::ADVERT);
    }

    public function testDropsSkillsTheAdvertDoesNotContain(): void
    {
        $requirements = $this->validator->validate([
            'required_skills' => ['PHP', 'Kubernetes', 'Terraform'],
            'nice_skills' => ['Docker', 'Haskell'],
        ], self::ADVERT);

        self::assertSame(['PHP'], $requirements->requiredSkills);
        self::assertSame(['Docker'], $requirements->niceSkills);
        self::assertCount(3, $requirements->warnings);
        self::assertStringContainsString('Kubernetes', $requirements->warnings[0]);
    }

    public function testASkillAppearsOnlyInTheMostDemandingList(): void
    {
        $requirements = $this->validator->validate([
            'required_skills' => ['PHP'],
            'nice_skills' => ['php', 'Docker'],
            'mentioned_skills' => ['PHP', 'DOCKER', 'Redis'],
        ], self::ADVERT);

        self::assertSame(['PHP'], $requirements->requiredSkills);
        self::assertSame(['Docker'], $requirements->niceSkills);
        self::assertSame(['Redis'], $requirements->mentionedSkills);
    }

    public function testGroundingIgnoresCaseAccentsAndPunctuation(): void
    {
        $requirements = $this->validator->validate([
            'required_skills' => ['gitlab ci cd', 'DÉVELOPPEUR', 'C++', 'C#'],
        ], 'Expérience en GitLab CI/CD, développeur C++ confirmé');

        self::assertSame(['gitlab ci cd', 'DÉVELOPPEUR', 'C++'], $requirements->requiredSkills);
    }

    public function testShortSkillsMustMatchAWholeWord(): void
    {
        $requirements = $this->validator->validate(['required_skills' => ['Go', 'R', 'C']], 'Expert Google Cloud et Rust');
        self::assertSame([], $requirements->requiredSkills, '« Go » n\'est pas « Google », ni « R » dans « Rust ».');

        $requirements = $this->validator->validate(['required_skills' => ['Go']], 'Développeur Go, Rust');
        self::assertSame(['Go'], $requirements->requiredSkills);
    }

    public function testSkillsAreSanitizedAndBounded(): void
    {
        $long = str_repeat('x', 61);
        $requirements = $this->validator->validate([
            'required_skills' => ['<b>PHP</b>', "Sym\nfony", $long, 42, null, ['PHP'], ''],
        ], 'PHP Symfony ' . $long);

        self::assertSame(['PHP', 'Sym fony'], array_slice($requirements->requiredSkills, 0, 2), 'Balises et retours à la ligne retirés.');
        self::assertNotContains($long, $requirements->requiredSkills, 'Trop long : écarté.');
        self::assertCount(2, $requirements->requiredSkills);
    }

    public function testSkillListsAreCapped(): void
    {
        $skills = array_map(static fn (int $i): string => 'Techno' . $i, range(1, 40));

        $requirements = $this->validator->validate(['required_skills' => $skills], implode(' ', $skills));

        self::assertCount(30, $requirements->requiredSkills);
        self::assertStringContainsString('tronqué', implode(' ', $requirements->warnings));
    }

    public function testAnInvalidFieldTypeIsIgnoredWithAWarning(): void
    {
        $requirements = $this->validator->validate(['required_skills' => 'PHP, Symfony', 'nice_skills' => ['Docker']], self::ADVERT);

        self::assertSame([], $requirements->requiredSkills);
        self::assertSame(['Docker'], $requirements->niceSkills);
        self::assertStringContainsString('required_skills', $requirements->warnings[0]);
    }

    public function testUnknownKeysAreIgnoredAndNeverInterpreted(): void
    {
        $requirements = $this->validator->validate([
            'required_skills' => ['PHP'],
            'score' => 100,
            'notify' => true,
            'message' => 'Postulez',
        ], self::ADVERT);

        self::assertSame(['PHP'], $requirements->requiredSkills);
        self::assertStringContainsString('score', $requirements->warnings[0]);
    }

    /** @return iterable<string, array{mixed, Seniority}> */
    public static function seniorities(): iterable
    {
        yield 'junior' => ['junior', Seniority::Junior];
        yield 'majuscules et espaces' => [' SENIOR ', Seniority::Senior];
        yield 'mid' => ['mid', Seniority::Mid];
        yield 'inconnue' => ['unknown', Seniority::Unknown];
        yield 'hors vocabulaire' => ['expert', Seniority::Unknown];
        yield 'type invalide' => [5, Seniority::Unknown];
        yield 'null' => [null, Seniority::Unknown];
    }

    #[DataProvider('seniorities')]
    public function testNormalizesSeniority(mixed $value, Seniority $expected): void
    {
        self::assertSame($expected, $this->validator->validate(['seniority' => $value], self::ADVERT)->seniority);
    }

    /** @return iterable<string, array{mixed, ?int}> */
    public static function years(): iterable
    {
        yield 'entier' => [5, 5];
        yield 'zéro' => [0, 0];
        yield 'flottant entier' => [3.0, 3];
        yield 'chaîne numérique' => ['4', 4];
        yield 'fourchette en texte' => ['5-7 ans', null];
        yield 'flottant non entier' => [2.5, null];
        yield 'négatif' => [-1, null];
        yield 'déraisonnable' => [99, null];
        yield 'null' => [null, null];
        yield 'booléen' => [true, null];
    }

    #[DataProvider('years')]
    public function testValidatesTheMinimumYearsOfExperience(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, $this->validator->validate(['min_years_experience' => $value], self::ADVERT)->minYearsExperience);
    }

    public function testLanguagesAreGroundedWithTheirSynonyms(): void
    {
        $requirements = $this->validator->validate([
            'languages' => [
                ['name' => 'English', 'level' => 'fluent', 'required' => true],
                ['name' => 'allemand', 'required' => false],
                'espagnol',
                ['name' => 'japonais'],
            ],
        ], 'Fluent in english, Deutsch appreciated, Spanish a plus');

        $names = array_map(static fn ($l): string => $l->name, $requirements->languages);
        self::assertSame(['English', 'allemand', 'espagnol'], $names, 'Le japonais n\'est pas dans l\'annonce.');
        self::assertTrue($requirements->languages[0]->required);
        self::assertFalse($requirements->languages[1]->required);
        self::assertFalse($requirements->languages[2]->required, 'Un nom seul n\'affirme pas que la langue est exigée.');
    }

    /** @return iterable<string, array{string, string, ?RemotePreference}> */
    public static function remoteModes(): iterable
    {
        yield 'remote justifié' => ['remote', 'Poste en full remote', RemotePreference::Remote];
        yield 'télétravail' => ['remote', 'Télétravail possible', RemotePreference::Remote];
        yield 'hybride justifié' => ['hybrid', 'Organisation hybride', RemotePreference::Hybrid];
        yield 'sur site justifié' => ['onsite', 'Présentiel obligatoire', RemotePreference::Onsite];
        yield 'remote inventé' => ['remote', 'Poste sur Marseille, bureaux en centre-ville', null];
        yield 'valeur inconnue' => ['teleportation', 'Full remote', null];
        yield 'null' => ['null', 'Full remote', null];
    }

    #[DataProvider('remoteModes')]
    public function testRemoteModeMustBeJustifiedByTheAdvert(string $value, string $advert, ?RemotePreference $expected): void
    {
        $mode = $value === 'null' ? null : $value;

        self::assertSame($expected, $this->validator->validate(['remote_mode' => $mode], $advert)->remoteMode);
    }

    public function testContractTypesMustBeMentionedByTheAdvert(): void
    {
        $requirements = $this->validator->validate([
            'contract_types' => ['cdi', 'freelance', 'CDD', 'portage', 'unknown', 'cdi', 42],
        ], 'Poste en CDI ou en CDD de remplacement');

        self::assertSame([ContractType::Cdi, ContractType::Cdd], $requirements->contractTypes);
    }

    public function testFreelanceIsRecognizedFromItsUsualVocabulary(): void
    {
        foreach (['Mission de 6 mois, TJM 500', 'Freelance PHP', 'Contractor wanted', 'Indépendant bienvenu'] as $advert) {
            self::assertSame([ContractType::Freelance], $this->validator->validate(['contract_types' => ['freelance']], $advert)->contractTypes, $advert);
        }
    }

    public function testInternshipAndApprenticeshipAreSupported(): void
    {
        $requirements = $this->validator->validate(['contract_types' => ['stage', 'alternance']], 'Stage ou alternance de 12 mois');

        self::assertSame([ContractType::Internship, ContractType::Apprenticeship], $requirements->contractTypes);
    }

    /** @return iterable<string, array{array<string, mixed>, string, ?array{?int, ?int, string, SalaryPeriod}}> */
    public static function salaries(): iterable
    {
        yield 'annuel en milliers' => [['min' => 45000, 'max' => 55000, 'currency' => 'EUR', 'period' => 'annual'], 'Salaire 45k-55k', [45000, 55000, 'EUR', SalaryPeriod::Annual]];
        yield 'annuel avec séparateurs' => [['min' => 45000, 'max' => 55000, 'currency' => 'EUR', 'period' => 'annual'], 'De 45 000 à 55.000 euros', [45000, 55000, 'EUR', SalaryPeriod::Annual]];
        yield 'journalier' => [['min' => 500, 'max' => 600, 'currency' => 'EUR', 'period' => 'daily'], 'TJM 500 - 600 €', [500, 600, 'EUR', SalaryPeriod::Daily]];
        yield 'minimum seul' => [['min' => 500, 'max' => null, 'currency' => 'EUR', 'period' => 'daily'], 'TJM à partir de 500 €', [500, null, 'EUR', SalaryPeriod::Daily]];
        yield 'symbole de devise' => [['min' => 60000, 'currency' => '$', 'period' => 'annual'], '60000 USD par an', [60000, null, 'USD', SalaryPeriod::Annual]];
        yield 'montant absent de l\'annonce' => [['min' => 70000, 'max' => 80000, 'currency' => 'EUR', 'period' => 'annual'], 'Salaire attractif selon profil', null];
        yield 'un seul montant retrouvé' => [['min' => 45000, 'max' => 99000, 'currency' => 'EUR', 'period' => 'annual'], 'Salaire 45 000 euros', null];
        yield 'minimum supérieur au maximum' => [['min' => 55000, 'max' => 45000, 'currency' => 'EUR', 'period' => 'annual'], '45000 55000', null];
        yield 'période mensuelle non prise en charge' => [['min' => 3500, 'max' => 4000, 'currency' => 'EUR', 'period' => 'monthly'], '3500 4000', null];
        yield 'devise absente' => [['min' => 45000, 'period' => 'annual'], '45000', null];
        yield 'devise invalide' => [['min' => 45000, 'currency' => 'euros', 'period' => 'annual'], '45000', null];
        yield 'aucun montant' => [['currency' => 'EUR', 'period' => 'annual'], 'Salaire confidentiel', null];
        yield 'montant négatif' => [['min' => -5, 'currency' => 'EUR', 'period' => 'annual'], '5', null];
    }

    /**
     * @param array<string, mixed> $salary
     * @param array{?int, ?int, string, SalaryPeriod}|null $expected
     */
    #[DataProvider('salaries')]
    public function testSalaryMustBeCompleteAndGroundedWithoutAnyConversion(array $salary, string $advert, ?array $expected): void
    {
        $result = $this->validator->validate(['salary' => $salary], $advert)->salary;

        if ($expected === null) {
            self::assertNull($result);

            return;
        }

        self::assertSame($expected[0], $result?->min);
        self::assertSame($expected[1], $result->max);
        self::assertSame($expected[2], $result->currency);
        self::assertSame($expected[3], $result->period);
    }

    public function testLocationsMustAppearInTheAdvert(): void
    {
        $requirements = $this->validator->validate(['locations' => ['Marseille', 'Paris', 'Marseille']], self::ADVERT);

        self::assertSame(['Marseille'], $requirements->locations);
    }

    public function testWarningsAreBounded(): void
    {
        $skills = array_map(static fn (int $i): string => 'Absent' . $i, range(1, 30));

        $requirements = $this->validator->validate(['required_skills' => $skills], self::ADVERT);

        self::assertLessThanOrEqual(12, count($requirements->warnings));
    }
}
