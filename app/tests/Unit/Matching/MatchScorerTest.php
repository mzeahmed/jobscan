<?php

declare(strict_types=1);

namespace App\Tests\Unit\Matching;

use App\DTO\Seniority;
use App\DTO\ContractType;
use App\Matching\JobFacts;
use App\Matching\MatchResult;
use App\Matching\MatchScorer;
use App\Matching\MatchVerdict;
use App\Profile\Model\Language;
use App\Matching\CriterionStatus;
use App\AI\Extraction\SalaryRange;
use App\Profile\Model\SalaryPeriod;
use App\Profile\Model\SearchProfile;
use App\AI\Extraction\JobRequirements;
use App\AI\Extraction\ExtractionSource;
use App\AI\Extraction\RequiredLanguage;
use App\Profile\Model\CandidateProfile;
use App\Profile\Model\RemotePreference;
use App\Profile\Model\SalaryExpectation;
use PHPUnit\Framework\Attributes\DataProvider;

final class MatchScorerTest extends MatchingTestCase
{
    private MatchScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new MatchScorer([['postgresql', 'postgres'], ['golang', 'go']]);
    }

    // --- Cas de référence ---------------------------------------------------------------------

    public function testAPerfectMatchScoresTheMaximumWithFullCoverage(): void
    {
        $result = $this->scorer->match($this->fullCandidate(), $this->fullProfile(), $this->perfectJob(), $this->now);

        self::assertSame(MatchVerdict::Eligible, $result->verdict);
        self::assertSame(100, $result->matchScore);
        self::assertSame(100, $result->coverage);
        self::assertSame([], $result->missingRequiredSkills);
        self::assertSame([], $result->eliminations);
        self::assertSame([], $result->unknown);
        foreach ($result->criteria as $criterion) {
            if ($criterion->criterion !== 'availability') {
                self::assertSame(CriterionStatus::Match, $criterion->status, $criterion->criterion);
            }
        }
        self::assertSame(CriterionStatus::NotApplicable, $result->criterion('availability')?->status);
    }

    public function testAMissingRequiredSkillLowersTheScoreAndIsListed(): void
    {
        $job = $this->facts(
            new JobRequirements(
                requiredSkills: ['PHP', 'Kubernetes'],
                niceSkills: ['Docker'],
                seniority: Seniority::Senior,
                minYearsExperience: 4,
                languages: [new RequiredLanguage('anglais', null, true)],
                remoteMode: RemotePreference::Remote,
                contractTypes: [ContractType::Cdi],
                salary: new SalaryRange(50000, 60000, 'EUR', SalaryPeriod::Annual),
            ),
            'Développeur back-end PHP',
            'Poste',
            'Marseille',
        );

        $result = $this->scorer->match($this->fullCandidate(), $this->fullProfile(), $job, $this->now);

        // Tous les critères sont satisfaits sauf les compétences exigées (1/2) : 100 - 40 × 0,5 sur 99 de poids.
        self::assertSame(80, $result->matchScore);
        self::assertSame(['Kubernetes'], $result->missingRequiredSkills);
        self::assertSame(CriterionStatus::Partial, $result->criterion('required_skills')?->status);
        self::assertSame(0.5, $result->criterion('required_skills')->score);
        self::assertStringContainsString('1/2', $result->criterion('required_skills')->detail);
        self::assertStringContainsString('Kubernetes', $result->criterion('required_skills')->detail);
        self::assertSame(MatchVerdict::Eligible, $result->verdict);
    }

    public function testNothingIsAssumedAboutSkillsTheCandidateDidNotDeclare(): void
    {
        $result = $this->scorer->match(
            $this->candidate(['PHP']),
            $this->profile(),
            $this->facts($this->requirements(['PHP', 'Symfony', 'Docker'])),
            $this->now,
        );

        self::assertSame(['Symfony', 'Docker'], $result->missingRequiredSkills);
        self::assertSame(['PHP'], $result->matchedSkills);
    }

    public function testSkillMatchingIgnoresAliasesCaseAndVersions(): void
    {
        $result = $this->scorer->match(
            $this->candidate(['PostgreSQL', 'PHP', 'Go']),
            $this->profile(),
            $this->facts($this->requirements(['Postgres', 'php 8.3', 'Golang'])),
            $this->now,
        );

        self::assertSame([], $result->missingRequiredSkills);
        self::assertSame(100, (int) round(100 * ($result->criterion('required_skills')->score ?? 0)));

        $withoutAliases = new MatchScorer()->match($this->candidate(['PostgreSQL']), $this->profile(), $this->facts($this->requirements(['Postgres'])), $this->now);
        self::assertSame(['Postgres'], $withoutAliases->missingRequiredSkills, 'Sans alias configuré, deux noms différents restent différents.');
    }

    // --- Une information inconnue n'est pas une incompatibilité ---------------------------------

    public function testUnknownInformationDoesNotLowerTheScoreButLowersTheCoverage(): void
    {
        $job = $this->facts($this->requirements(['PHP', 'Symfony']), 'Développeur back-end', 'Poste');
        $candidateWithoutPreferences = $this->candidate();
        $candidateWithPreferences = $this->candidate(
            remoteModes: [RemotePreference::Remote],
            contracts: [ContractType::Cdi],
            salary: [new SalaryExpectation(ContractType::Cdi, SalaryPeriod::Annual, 45000, null, 'EUR')],
        );
        $profile = $this->profile(titles: ['Développeur back-end']);

        $without = $this->scorer->match($candidateWithoutPreferences, $profile, $job, $this->now);
        $with = $this->scorer->match($candidateWithPreferences, $profile, $job, $this->now);

        self::assertSame($without->matchScore, $with->matchScore, 'Le mode de travail, le contrat et le salaire sont inconnus dans l\'annonce : ils ne comptent pas.');
        self::assertLessThan($without->coverage, $with->coverage, 'Trois critères de plus restent à évaluer faute d\'information.');
        self::assertCount(4, $with->unknown, 'Mode de travail, contrat, rémunération… et séniorité, que l\'annonce ne précise pas.');
        self::assertSame(CriterionStatus::Unknown, $with->criterion('remote')?->status);
        self::assertSame(CriterionStatus::Unknown, $with->criterion('contract')?->status);
        self::assertSame(CriterionStatus::Unknown, $with->criterion('salary')?->status);
    }

    public function testAConstatedMismatchDoesLowerTheScore(): void
    {
        $profile = $this->profile(locations: ['Marseille']);
        $candidate = $this->candidate();

        $matching = $this->scorer->match($candidate, $profile, $this->facts($this->requirements(['PHP']), location: 'Marseille'), $this->now);
        $elsewhere = $this->scorer->match($candidate, $profile, $this->facts($this->requirements(['PHP']), location: 'Lille'), $this->now);

        self::assertSame(CriterionStatus::Mismatch, $elsewhere->criterion('location')?->status);
        self::assertLessThan($matching->matchScore, $elsewhere->matchScore);
        self::assertNotSame(MatchVerdict::Eliminated, $elsewhere->verdict, 'Un lieu différent pénalise le score mais n\'élimine pas.');
    }

    public function testAJobThatSaysNothingIsInsufficientDataNotAFailure(): void
    {
        $candidate = $this->candidate(
            cities: ['Marseille'],
            remoteModes: [RemotePreference::Remote],
            contracts: [ContractType::Cdi],
            salary: [new SalaryExpectation(null, SalaryPeriod::Annual, 45000, null, 'EUR')],
            experiences: [$this->experience('A', 'B', '2015-01')],
        );
        $profile = $this->profile(titles: ['Développeur PHP']);

        $result = $this->scorer->match($candidate, $profile, $this->facts(new JobRequirements(), 'Développeur PHP'), $this->now);

        self::assertSame(MatchVerdict::InsufficientData, $result->verdict);
        self::assertLessThan(40, $result->coverage);
        self::assertNotEmpty($result->unknown);
    }

    public function testHeuristicRequirementsMakeTheRequiredSkillsUnknown(): void
    {
        $requirements = new JobRequirements(mentionedSkills: ['php', 'symfony'], source: ExtractionSource::Heuristic);

        $result = $this->scorer->match($this->candidate(), $this->profile(), $this->facts($requirements), $this->now);

        self::assertSame(CriterionStatus::Unknown, $result->criterion('required_skills')?->status);
        self::assertSame(ExtractionSource::Heuristic, $result->requirementsSource);
        self::assertSame(CriterionStatus::Match, $result->criterion('nice_skills')?->status, 'Les technologies citées comptent comme compétences appréciées.');
        self::assertStringContainsString('heuristique', implode(' ', $result->unknown));
    }

    // --- Verdicts -------------------------------------------------------------------------------

    public function testTheVerdictFollowsTheProfileThreshold(): void
    {
        $job = $this->facts($this->requirements(['PHP', 'Symfony', 'Rust', 'Elixir']), 'Développeur');

        $below = $this->scorer->match($this->candidate(), $this->profile(minScore: 80), $job, $this->now);
        $eligible = $this->scorer->match($this->candidate(), $this->profile(minScore: 0), $job, $this->now);

        self::assertSame(MatchVerdict::BelowThreshold, $below->verdict);
        self::assertSame(MatchVerdict::Eligible, $eligible->verdict);
        self::assertSame($below->matchScore, $eligible->matchScore, 'Le seuil ne change pas le score, seulement le verdict.');
    }

    public function testTheThresholdIsInclusive(): void
    {
        $job = $this->facts($this->requirements(['PHP', 'Rust']), 'Développeur');
        $score = $this->scorer->match($this->candidate(), $this->profile(minScore: 0), $job, $this->now)->matchScore;

        self::assertSame(MatchVerdict::Eligible, $this->scorer->match($this->candidate(), $this->profile(minScore: $score), $job, $this->now)->verdict);
        self::assertSame(MatchVerdict::BelowThreshold, $this->scorer->match($this->candidate(), $this->profile(minScore: $score + 1), $job, $this->now)->verdict);
    }

    // --- Critères éliminatoires -----------------------------------------------------------------

    /** @return iterable<string, array{SearchProfile, CandidateProfile, JobFacts, string}> */
    public static function eliminations(): iterable
    {
        yield from [
            'mot-clé exclu dans la description' => [
                self::profile(excludedKeywords: ['alternance']),
                self::candidate(),
                self::facts(self::requirements(['PHP']), 'Développeur PHP', 'Poste en alternance de 12 mois'),
                'Mot-clé exclu « alternance »',
            ],
            'mot-clé exclu dans le titre' => [
                self::profile(excludedKeywords: ['stage']),
                self::candidate(),
                self::facts(self::requirements(['PHP']), 'Stage développeur PHP'),
                'Mot-clé exclu « stage »',
            ],
            'intitulé exclu' => [
                self::profile(excludedTitles: ['Chef de projet']),
                self::candidate(),
                self::facts(self::requirements(['PHP']), 'Chef de projet technique PHP'),
                'Intitulé exclu « Chef de projet »',
            ],
            'compétence exclue par le profil' => [
                self::profile(excludedSkills: ['Java']),
                self::candidate(),
                self::facts(self::requirements(['PHP', 'java'])),
                'exige « java »',
            ],
            'compétence exclue par le candidat' => [
                self::profile(),
                self::candidate(excludedSkills: ['COBOL']),
                self::facts(self::requirements(['Cobol'])),
                'exige « Cobol »',
            ],
            'contrat hors préférences du profil' => [
                self::profile(contracts: [ContractType::Cdi]),
                self::candidate(),
                self::facts(new JobRequirements(requiredSkills: ['PHP'], contractTypes: [ContractType::Cdd])),
                'Contrat proposé (cdd)',
            ],
            'contrat hors préférences du candidat' => [
                self::profile(),
                self::candidate(contracts: [ContractType::Freelance]),
                self::facts(new JobRequirements(requiredSkills: ['PHP'], contractTypes: [ContractType::Cdi, ContractType::Internship])),
                'Contrat proposé (cdi, stage)',
            ],
            'mode de travail hors préférences' => [
                self::profile(remoteModes: [RemotePreference::Remote]),
                self::candidate(),
                self::facts(new JobRequirements(requiredSkills: ['PHP'], remoteMode: RemotePreference::Onsite)),
                'Mode de travail (onsite)',
            ],
        ];
    }

    #[DataProvider('eliminations')]
    public function testEliminatoryCriteriaDiscardTheJobWhateverItsScore(SearchProfile $profile, CandidateProfile $candidate, JobFacts $facts, string $expected): void
    {
        $result = $this->scorer->match($candidate, $profile, $facts, $this->now);

        self::assertSame(MatchVerdict::Eliminated, $result->verdict);
        self::assertNotEmpty($result->eliminations);
        self::assertStringContainsString($expected, implode(' ', $result->eliminations));
        self::assertNotEmpty($result->criteria, 'Le détail du score reste calculé et explicable : l\'élimination est indépendante.');
    }

    public function testAnEliminationIsIndependentOfAnExcellentScore(): void
    {
        $job = $this->perfectJob(['PHP', 'Symfony'], 'Développeur back-end PHP', 'Poste en alternance');

        $result = $this->scorer->match($this->fullCandidate(), $this->fullProfile(excludedKeywords: ['alternance']), $job, $this->now);

        self::assertSame(100, $result->matchScore);
        self::assertSame(MatchVerdict::Eliminated, $result->verdict);
    }

    public function testUnknownContractAndWorkModeNeverEliminate(): void
    {
        $result = $this->scorer->match(
            $this->candidate(contracts: [ContractType::Cdi], remoteModes: [RemotePreference::Remote]),
            $this->profile(),
            $this->facts($this->requirements(['PHP'])),
            $this->now,
        );

        self::assertSame([], $result->eliminations);
        self::assertNotSame(MatchVerdict::Eliminated, $result->verdict);
    }

    public function testOnlyRequiredSkillsCanEliminate(): void
    {
        $result = $this->scorer->match(
            $this->candidate(excludedSkills: ['Java']),
            $this->profile(excludedSkills: ['Rust']),
            $this->facts(new JobRequirements(requiredSkills: ['PHP'], niceSkills: ['Java'], mentionedSkills: ['Rust'])),
            $this->now,
        );

        self::assertSame([], $result->eliminations, 'Une compétence appréciée ou citée ne rend pas l\'offre éliminatoire.');
    }

    public function testExcludedKeywordsMatchWholeWordsOnly(): void
    {
        $result = $this->scorer->match(
            $this->candidate(),
            $this->profile(excludedKeywords: ['stage']),
            $this->facts($this->requirements(['PHP']), 'Développeur PHP', 'Équipe de backstage technique, bon stagiaire accepté'),
            $this->now,
        );

        self::assertSame([], $result->eliminations);
    }

    public function testAProfileFallsBackToTheCandidatePreferences(): void
    {
        $candidate = $this->candidate(contracts: [ContractType::Cdi]);
        $job = $this->facts(new JobRequirements(requiredSkills: ['PHP'], contractTypes: [ContractType::Freelance]));

        $inherits = $this->scorer->match($candidate, $this->profile(), $job, $this->now);
        $overrides = $this->scorer->match($candidate, $this->profile(contracts: [ContractType::Freelance]), $job, $this->now);

        self::assertSame(MatchVerdict::Eliminated, $inherits->verdict);
        self::assertNotSame(MatchVerdict::Eliminated, $overrides->verdict, 'Le profil de recherche prime sur le candidat.');
    }

    // --- Pondérations ---------------------------------------------------------------------------

    public function testProfileWeightsChangeTheScoreAndZeroNeutralizesACriterion(): void
    {
        $job = $this->facts($this->requirements(['PHP', 'Rust'], ['Docker']), 'Développeur');

        $default = $this->scorer->match($this->candidate(), $this->profile(), $job, $this->now);
        $niceHeavy = $this->scorer->match($this->candidate(), $this->profile(weights: ['required_skills' => 10, 'nice_skills' => 90]), $job, $this->now);
        $ignoreRequired = $this->scorer->match($this->candidate(), $this->profile(weights: ['required_skills' => 0]), $job, $this->now);

        self::assertGreaterThan($default->matchScore, $niceHeavy->matchScore);
        self::assertGreaterThan($default->matchScore, $ignoreRequired->matchScore);
        self::assertSame(0, $ignoreRequired->criterion('required_skills')?->weight);
    }

    public function testTheScoreIsNormalizedOverTheWeightsThatApply(): void
    {
        // Un seul critère évaluable (compétences exigées) : son poids ne change pas le score.
        $job = $this->facts($this->requirements(['PHP', 'Rust']), 'Développeur');

        $a = $this->scorer->match($this->candidate(), $this->profile(weights: ['required_skills' => 40]), $job, $this->now);
        $b = $this->scorer->match($this->candidate(), $this->profile(weights: ['required_skills' => 400]), $job, $this->now);

        self::assertSame($a->matchScore, $b->matchScore);
    }

    // --- Pertinence du poste --------------------------------------------------------------------

    /** @return iterable<string, array{SearchProfile, string, string, CriterionStatus, float}> */
    public static function titles(): iterable
    {
        yield from [
            'intitulé recherché dans le titre' => [self::profile(titles: ['Développeur back-end']), 'Développeur back-end PHP senior', '', CriterionStatus::Match, 1.0],
            'titre qui partage l\'essentiel' => [self::profile(titles: ['Développeur back-end Symfony']), 'Développeur back-end PHP', '', CriterionStatus::Match, 0.8],
            'mot-clé dans le titre' => [self::profile(keywords: ['symfony']), 'Expert Symfony', '', CriterionStatus::Partial, 0.7],
            'mot-clé dans la description' => [self::profile(keywords: ['symfony']), 'Ingénieur logiciel', 'Stack Symfony et Docker', CriterionStatus::Partial, 0.4],
            'compétence ciblée citée' => [self::profile(primary: ['Symfony']), 'Ingénieur logiciel', '', CriterionStatus::Partial, 0.5],
            'rien ne correspond' => [self::profile(titles: ['Développeur back-end'], keywords: ['symfony']), 'Comptable', 'Tenue de comptabilité', CriterionStatus::Mismatch, 0.0],
        ];
    }

    #[DataProvider('titles')]
    public function testJobRelevanceUsesTitleKeywordsAndTargetedSkills(SearchProfile $profile, string $title, string $description, CriterionStatus $status, float $score): void
    {
        $requirements = new JobRequirements(requiredSkills: $profile->primarySkills === [] ? [] : ['Symfony']);

        $criterion = $this->scorer->match($this->candidate(), $profile, $this->facts($requirements, $title, $description), $this->now)->criterion('title');

        self::assertSame($status, $criterion?->status);
        self::assertSame($score, $criterion->score);
    }

    public function testRelevanceIsNotApplicableWhenTheProfileTargetsNothing(): void
    {
        $result = $this->scorer->match($this->candidate(), $this->profile(), $this->facts($this->requirements(['PHP'])), $this->now);

        self::assertSame(CriterionStatus::NotApplicable, $result->criterion('title')?->status);
    }

    public function testTheCandidateTargetTitlesApplyWhenTheProfileHasNone(): void
    {
        $result = $this->scorer->match($this->candidate(titles: ['Développeur Symfony']), $this->profile(), $this->facts($this->requirements(['PHP']), 'Développeur Symfony confirmé'), $this->now);

        self::assertSame(CriterionStatus::Match, $result->criterion('title')?->status);
    }

    // --- Lieu et mode de travail -----------------------------------------------------------------

    /** @return iterable<string, array{list<string>, ?RemotePreference, ?string, list<string>, CriterionStatus}> */
    public static function locations(): iterable
    {
        yield 'ville de l\'annonce' => [['Marseille'], null, 'Marseille 13001', [], CriterionStatus::Match];
        yield 'ville parmi les lieux extraits' => [['Marseille'], null, null, ['Aix-en-Provence', 'Marseille'], CriterionStatus::Match];
        yield 'lieu composé' => [['Aix-en-Provence'], null, 'Aix-en-Provence (13)', [], CriterionStatus::Match];
        yield 'poste en télétravail complet' => [['Marseille'], RemotePreference::Remote, 'Paris', [], CriterionStatus::Match];
        yield 'poste hybride dans une autre ville' => [['Marseille'], RemotePreference::Hybrid, 'Paris', [], CriterionStatus::Mismatch];
        yield 'autre ville' => [['Marseille'], null, 'Lille', [], CriterionStatus::Mismatch];
        yield 'lieu inconnu' => [['Marseille'], RemotePreference::Hybrid, null, [], CriterionStatus::Unknown];
        yield 'remote souhaité, télétravail cité' => [['Remote'], null, 'Télétravail France', [], CriterionStatus::Match];
        yield 'remote souhaité, poste sur site' => [['Remote'], null, 'Lille', [], CriterionStatus::Mismatch];
        yield 'aucun lieu souhaité' => [[], null, 'Lille', [], CriterionStatus::NotApplicable];
    }

    /**
     * @param list<string> $wanted
     * @param list<string> $extracted
     */
    #[DataProvider('locations')]
    public function testLocationCriterion(array $wanted, ?RemotePreference $mode, ?string $location, array $extracted, CriterionStatus $expected): void
    {
        $requirements = new JobRequirements(remoteMode: $mode, locations: $extracted);

        $result = $this->scorer->match($this->candidate(), $this->profile(locations: $wanted), $this->facts($requirements, location: $location), $this->now);

        self::assertSame($expected, $result->criterion('location')?->status);
    }

    public function testThePreferredWorkModeScoresHigherThanAnAcceptedOne(): void
    {
        $profile = $this->profile(remoteModes: [RemotePreference::Remote, RemotePreference::Hybrid]);

        $preferred = $this->scorer->match($this->candidate(), $profile, $this->facts(new JobRequirements(remoteMode: RemotePreference::Remote)), $this->now);
        $accepted = $this->scorer->match($this->candidate(), $profile, $this->facts(new JobRequirements(remoteMode: RemotePreference::Hybrid)), $this->now);

        self::assertSame(1.0, $preferred->criterion('remote')?->score);
        self::assertSame(0.8, $accepted->criterion('remote')?->score);
        self::assertSame(CriterionStatus::Partial, $accepted->criterion('remote')->status);
    }

    // --- Rémunération ----------------------------------------------------------------------------

    /** @return iterable<string, array{?SalaryRange, CriterionStatus, ?float}> */
    public static function salaries(): iterable
    {
        yield 'au-dessus de la prétention' => [new SalaryRange(50000, 60000, 'EUR', SalaryPeriod::Annual), CriterionStatus::Match, 1.0];
        yield 'minimum annoncé suffisant' => [new SalaryRange(46000, null, 'EUR', SalaryPeriod::Annual), CriterionStatus::Match, 1.0];
        yield 'fourchette qui recoupe' => [new SalaryRange(40000, 50000, 'EUR', SalaryPeriod::Annual), CriterionStatus::Partial, 0.7];
        yield 'un peu en dessous' => [new SalaryRange(35000, 40000, 'EUR', SalaryPeriod::Annual), CriterionStatus::Partial, 0.667];
        yield 'très en dessous' => [new SalaryRange(30000, 32000, 'EUR', SalaryPeriod::Annual), CriterionStatus::Mismatch, 0.133];
        yield 'très très en dessous' => [new SalaryRange(10000, 20000, 'EUR', SalaryPeriod::Annual), CriterionStatus::Mismatch, 0.0];
        yield 'non annoncée' => [null, CriterionStatus::Unknown, null];
        yield 'autre devise : pas de conversion' => [new SalaryRange(80000, 90000, 'USD', SalaryPeriod::Annual), CriterionStatus::Unknown, null];
        yield 'période différente : non comparable' => [new SalaryRange(500, 600, 'EUR', SalaryPeriod::Daily), CriterionStatus::NotApplicable, null];
    }

    #[DataProvider('salaries')]
    public function testSalaryCriterion(?SalaryRange $salary, CriterionStatus $expected, ?float $score): void
    {
        $candidate = $this->candidate(salary: [new SalaryExpectation(ContractType::Cdi, SalaryPeriod::Annual, 45000, null, 'EUR')]);

        $criterion = $this->scorer->match($candidate, $this->profile(), $this->facts(new JobRequirements(contractTypes: [ContractType::Cdi], salary: $salary)), $this->now)->criterion('salary');

        self::assertSame($expected, $criterion?->status);
        self::assertEqualsWithDelta($score, $criterion->score, 0.001);
    }

    public function testSalaryExpectationsAreMatchedByPeriodAndContract(): void
    {
        $candidate = $this->candidate(salary: [
            new SalaryExpectation(ContractType::Cdi, SalaryPeriod::Annual, 45000, null, 'EUR'),
            new SalaryExpectation(ContractType::Freelance, SalaryPeriod::Daily, 500, null, 'EUR'),
        ]);

        $freelance = $this->scorer->match($candidate, $this->profile(), $this->facts(new JobRequirements(contractTypes: [ContractType::Freelance], salary: new SalaryRange(550, 600, 'EUR', SalaryPeriod::Daily))), $this->now);
        $wrongContract = $this->scorer->match($this->candidate(salary: [new SalaryExpectation(ContractType::Freelance, SalaryPeriod::Annual, 45000, null, 'EUR')]), $this->profile(), $this->facts(new JobRequirements(contractTypes: [ContractType::Cdi], salary: new SalaryRange(50000, null, 'EUR', SalaryPeriod::Annual))), $this->now);

        self::assertSame(CriterionStatus::Match, $freelance->criterion('salary')?->status);
        self::assertSame(CriterionStatus::NotApplicable, $wrongContract->criterion('salary')?->status);
    }

    public function testNoExpectationMeansNoSalaryCriterion(): void
    {
        $result = $this->scorer->match($this->candidate(), $this->profile(), $this->facts(new JobRequirements(salary: new SalaryRange(50000, null, 'EUR', SalaryPeriod::Annual))), $this->now);

        self::assertSame(CriterionStatus::NotApplicable, $result->criterion('salary')?->status);
    }

    // --- Séniorité et expérience ------------------------------------------------------------------

    /** @return iterable<string, array{?int, Seniority, bool, CriterionStatus}> */
    public static function seniority(): iterable
    {
        yield 'années demandées couvertes' => [5, Seniority::Unknown, true, CriterionStatus::Match];
        yield 'années demandées, expérience insuffisante' => [15, Seniority::Unknown, true, CriterionStatus::Partial];
        yield 'très insuffisante' => [40, Seniority::Unknown, true, CriterionStatus::Mismatch];
        yield 'niveau senior (5 ans)' => [null, Seniority::Senior, true, CriterionStatus::Match];
        yield 'niveau junior' => [null, Seniority::Junior, true, CriterionStatus::Match];
        yield 'rien dans l\'annonce' => [null, Seniority::Unknown, true, CriterionStatus::Unknown];
        yield 'expérience du candidat non déclarée' => [5, Seniority::Unknown, false, CriterionStatus::Unknown];
    }

    #[DataProvider('seniority')]
    public function testSeniorityCriterion(?int $years, Seniority $level, bool $declared, CriterionStatus $expected): void
    {
        $candidate = $this->candidate(experiences: $declared ? [$this->experience('A', 'Dev', '2015-01')] : []);

        $result = $this->scorer->match($candidate, $this->profile(), $this->facts($this->requirements([], [], $level, $years)), $this->now);

        self::assertSame($expected, $result->criterion('seniority')?->status);
    }

    public function testExperienceIsNeverPenalizedWhenItExceedsTheRequirement(): void
    {
        $candidate = $this->candidate(experiences: [$this->experience('A', 'Dev', '2000-01')]);

        $result = $this->scorer->match($candidate, $this->profile(), $this->facts($this->requirements([], [], Seniority::Junior)), $this->now);

        self::assertSame(1.0, $result->criterion('seniority')?->score);
    }

    // --- Langues ---------------------------------------------------------------------------------

    public function testRequiredLanguagesAreComparedWhateverTheirSpelling(): void
    {
        $requirements = new JobRequirements(languages: [new RequiredLanguage('anglais', null, true), new RequiredLanguage('allemand', null, false)]);
        $candidate = $this->candidate(languages: [new Language('English', 'B2'), new Language('Français', 'natif')]);

        $result = $this->scorer->match($candidate, $this->profile(), $this->facts($requirements), $this->now);

        self::assertSame(CriterionStatus::Match, $result->criterion('languages')?->status);
    }

    public function testAMissingRequiredLanguageIsReported(): void
    {
        $requirements = new JobRequirements(languages: [new RequiredLanguage('anglais', null, true), new RequiredLanguage('allemand', null, true)]);
        $candidate = $this->candidate(languages: [new Language('English', null)]);

        $criterion = $this->scorer->match($candidate, $this->profile(), $this->facts($requirements), $this->now)->criterion('languages');

        self::assertSame(CriterionStatus::Partial, $criterion?->status);
        self::assertStringContainsString('allemand', $criterion->detail);
    }

    public function testLanguagesAreUnknownWhenTheCandidateDeclaredNone(): void
    {
        $requirements = new JobRequirements(languages: [new RequiredLanguage('anglais', null, true)]);

        $result = $this->scorer->match($this->candidate(), $this->profile(), $this->facts($requirements), $this->now);

        self::assertSame(CriterionStatus::Unknown, $result->criterion('languages')?->status);
    }

    public function testLanguagesJustAppreciatedAreNotACriterion(): void
    {
        $requirements = new JobRequirements(languages: [new RequiredLanguage('anglais', null, false)]);

        $result = $this->scorer->match($this->candidate(languages: [new Language('Français', null)]), $this->profile(), $this->facts($requirements), $this->now);

        self::assertSame(CriterionStatus::NotApplicable, $result->criterion('languages')?->status);
    }

    // --- Explication -----------------------------------------------------------------------------

    public function testArgumentsAreDrawnOnlyFromWhatTheCandidateDeclared(): void
    {
        $candidate = $this->candidate(
            skills: [$this->skillLevel('PHP', \App\Profile\Model\SkillLevel::Expert, 8), 'Symfony'],
            experiences: [
                $this->experience('Exemple SAS', 'Développeur back-end', '2018-01', '2022-12', 'PHP', 'Symfony'),
                $this->experience('Autre SA', 'Comptable', '2010-01', '2012-01', 'Excel'),
            ],
            languages: [new Language('Anglais', 'B2')],
        );
        $job = $this->facts(new JobRequirements(requiredSkills: ['PHP', 'Symfony'], languages: [new RequiredLanguage('english', null, true)]));

        $result = $this->scorer->match($candidate, $this->profile(cv: 'backend-php'), $job, $this->now);
        $text = implode("\n", $result->arguments);

        self::assertStringContainsString('PHP (expert, 8 an(s))', $text);
        self::assertStringContainsString('Symfony', $text);
        self::assertStringContainsString('Développeur back-end chez Exemple SAS', $text);
        self::assertStringNotContainsString('Comptable', $text, 'Une expérience sans rapport avec l\'annonce n\'est pas mise en avant.');
        self::assertStringContainsString('Anglais (B2)', $text);
        self::assertSame('backend-php', $result->cvId);
    }

    public function testNoArgumentIsInventedWhenNothingMatches(): void
    {
        $result = $this->scorer->match($this->candidate(['COBOL']), $this->profile(), $this->facts($this->requirements(['Rust'])), $this->now);

        self::assertSame([], $result->arguments);
        self::assertNull($result->cvId);
    }

    public function testNiceAndMissingNiceSkillsAreListedSeparately(): void
    {
        $result = $this->scorer->match($this->candidate(), $this->profile(), $this->facts($this->requirements(['PHP'], ['Docker', 'Kubernetes'])), $this->now);

        self::assertSame(['PHP', 'Docker'], $result->matchedSkills);
        self::assertSame(['Kubernetes'], $result->missingNiceSkills);
        self::assertSame([], $result->missingRequiredSkills);
    }

    public function testTheResultIsDeterministic(): void
    {
        $job = $this->perfectJob();

        $first = $this->scorer->match($this->fullCandidate(), $this->fullProfile(), $job, $this->now);
        $second = new MatchScorer([['postgresql', 'postgres'], ['golang', 'go']])->match($this->fullCandidate(), $this->fullProfile(), $job, $this->now);

        self::assertEquals($first, $second);
    }

    public function testTheResultSurvivesAJsonRoundTrip(): void
    {
        $result = $this->scorer->match($this->fullCandidate(), $this->fullProfile(), $this->perfectJob(['PHP', 'Kubernetes']), $this->now);

        $restored = MatchResult::fromArray(json_decode(json_encode($result->toArray(), JSON_THROW_ON_ERROR), true));

        self::assertEquals($result, $restored);
    }

    public function testAnUnreadableStoredResultIsRejected(): void
    {
        self::assertNull(MatchResult::fromArray([]));
        self::assertNull(MatchResult::fromArray(['verdict' => 'inconnu', 'search_profile_id' => 'a']));
        self::assertNull(MatchResult::fromArray(['verdict' => 'eligible']));
    }

    public function testTheSignatureCoversWhatChangesTheResult(): void
    {
        $a = new MatchScorer([['a', 'b']], ['junior' => 0, 'mid' => 2, 'senior' => 5], 40)->signature();

        self::assertNotSame($a, new MatchScorer([['a', 'c']], ['junior' => 0, 'mid' => 2, 'senior' => 5], 40)->signature());
        self::assertNotSame($a, new MatchScorer([['a', 'b']], ['junior' => 0, 'mid' => 3, 'senior' => 5], 40)->signature());
        self::assertNotSame($a, new MatchScorer([['a', 'b']], ['junior' => 0, 'mid' => 2, 'senior' => 5], 60)->signature());
        self::assertSame(MatchScorer::RULES_VERSION, $a['rules_version']);
    }

    // --- Fabriques propres à ces tests -------------------------------------------------------------

    private function fullCandidate(): CandidateProfile
    {
        return $this->candidate(
            skills: ['PHP', 'Symfony', 'Docker', 'PostgreSQL'],
            experiences: [$this->experience('Exemple SAS', 'Développeur', '2015-01', null, 'PHP', 'Symfony')],
            cities: ['Marseille'],
            remoteModes: [RemotePreference::Remote, RemotePreference::Hybrid],
            contracts: [ContractType::Cdi],
            salary: [new SalaryExpectation(ContractType::Cdi, SalaryPeriod::Annual, 45000, null, 'EUR')],
            languages: [new Language('Anglais', 'B2')],
        );
    }

    /**
     * @param list<string> $excludedKeywords
     */
    private function fullProfile(array $excludedKeywords = []): SearchProfile
    {
        return $this->profile(titles: ['Développeur back-end'], excludedKeywords: $excludedKeywords);
    }

    /** @param list<string> $required */
    private function perfectJob(array $required = ['PHP', 'Symfony'], string $title = 'Développeur back-end PHP', string $description = 'Poste de développeur'): JobFacts
    {
        return $this->facts(
            new JobRequirements(
                requiredSkills: $required,
                niceSkills: ['Docker'],
                seniority: Seniority::Senior,
                minYearsExperience: 4,
                languages: [new RequiredLanguage('anglais', null, true)],
                remoteMode: RemotePreference::Remote,
                contractTypes: [ContractType::Cdi],
                salary: new SalaryRange(50000, 60000, 'EUR', SalaryPeriod::Annual),
            ),
            $title,
            $description,
            'Marseille',
        );
    }
}
