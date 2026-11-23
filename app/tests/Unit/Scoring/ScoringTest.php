<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scoring;

use App\DTO\JobDto;
use App\DTO\Seniority;
use App\Scoring\Scoring;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ScoringTest extends TestCase
{
    private Scoring $service;

    protected function setUp(): void
    {
        $this->service = new Scoring([
            'prescore' => [
                'keywords' => [
                    'php' => 10,
                    'symfony' => 15,
                    'wordpress' => 10,
                ],
                'remote_keywords' => ['remote', 'télétravail', 'teletravail'],
                'remote_bonus' => 5,
                'negative_keywords' => [
                    'stage' => -50,
                    'alternance' => -50,
                ],
            ],
            'compute' => [
                'title_keywords' => ['php' => 20],
                'stack_keywords' => ['symfony' => 30, 'wordpress' => 20],
                'contract_bonuses' => ['freelance' => 15, 'cdi' => 10],
                'flag_bonuses' => ['remote' => 10, 'recent' => 20],
                'description_keywords' => ['mission' => 10, 'urgent' => 15, 'asap' => 15],
                'negative_keywords' => ['stage' => -50, 'alternance' => -50],
                'seniority_bonuses' => ['senior' => 15, 'mid' => 5, 'junior' => -10],
                'budget_bonus' => ['min_daily_rate' => 500, 'min_annual_salary' => 55, 'points' => 10],
                'relevance' => [
                    'max_points' => 20,
                    'target_languages' => ['php', 'wordpress'],
                    'off_target_penalty' => -30,
                    'location_penalty' => -15,
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // preScore
    // -------------------------------------------------------------------------

    public function testPreScoreMatchesKeywords(): void
    {
        $job = $this->job('Développeur Symfony', 'Projet PHP backend');

        $this->assertSame(25, $this->service->preScore($job)); // php:10 + symfony:15
    }

    public function testPreScoreRemoteTriggersOnce(): void
    {
        // Both "remote" and "télétravail" present — bonus applied only once
        $job = $this->job('PHP remote', 'Poste en télétravail complet');

        $score = $this->service->preScore($job);

        $this->assertSame(15, $score); // php:10 + remote_bonus:5 (once)
    }

    public function testPreScoreRemoteVariants(): void
    {
        $this->assertSame(5, $this->service->preScore($this->job('', 'teletravail possible')));
        $this->assertSame(5, $this->service->preScore($this->job('', 'télétravail possible')));
        $this->assertSame(5, $this->service->preScore($this->job('remote job', '')));
    }

    public function testPreScorePenalizesStage(): void
    {
        $job = $this->job('Stage PHP Symfony', 'Mission intéressante');

        $this->assertSame(-25, $this->service->preScore($job)); // php:10 + symfony:15 + stage:-50
    }

    public function testPreScorePenalizesAlternance(): void
    {
        $job = $this->job('Alternance développeur', 'PHP Symfony');

        $this->assertSame(-25, $this->service->preScore($job)); // php:10 + symfony:15 + alternance:-50
    }

    public function testPreScoreNoMatchReturnsZero(): void
    {
        $job = $this->job('Chef de projet marketing', 'Gestion de campagnes');

        $this->assertSame(0, $this->service->preScore($job));
    }

    public function testPreScoreIsCaseInsensitive(): void
    {
        $job = $this->job('PHP SYMFONY DEVELOPER', 'REMOTE POSITION');

        $this->assertSame(30, $this->service->preScore($job)); // php:10 + symfony:15 + remote:5
    }

    // -------------------------------------------------------------------------
    // compute
    // -------------------------------------------------------------------------

    public function testComputeSymfonyFreelanceRemoteRecent(): void
    {
        $job = $this->job('Développeur PHP Symfony', 'Mission freelance remote');
        $ai = $this->ai(stack: ['php', 'symfony'], contractType: ContractType::Freelance, freelance: true, remote: true, recent: true);

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(100, $score); // php titre:20 + symfony stack:30 + freelance:15 + remote:10 + recent:20 + mission:10 = 105 → clamped
        $this->assertContains('+20 (php titre)', $breakdown);
        $this->assertContains('+30 (symfony stack)', $breakdown);
        $this->assertContains('+15 (freelance)', $breakdown);
        $this->assertContains('+10 (remote)', $breakdown);
        $this->assertContains('+20 (recent)', $breakdown);
    }

    public function testComputeWordPressCdi(): void
    {
        $job = $this->job('Développeur WordPress', 'Poste CDI Paris');
        $ai = $this->ai(stack: ['wordpress', 'php'], contractType: ContractType::Cdi);

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(30, $score); // wordpress stack:20 + cdi:10
    }

    public function testComputeDescriptionKeywordsUrgentAndMission(): void
    {
        $job = $this->job('Développeur PHP', 'Mission urgent à pourvoir');
        $ai = $this->ai();

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(45, $score); // php titre:20 + mission:10 + urgent:15
        $this->assertContains('+10 (mission)', $breakdown);
        $this->assertContains('+15 (urgent)', $breakdown);
    }

    public function testComputePenalizesStage(): void
    {
        $job = $this->job('Développeur PHP', 'Offre de stage symfony');
        $ai = $this->ai(stack: ['symfony']);

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(0, $score); // php:20 + symfony:30 + stage:-50 = 0 (clamped)
    }

    public function testComputeScoreIsClampedAt100(): void
    {
        $job = $this->job('php developer', 'mission urgent asap');
        $ai = $this->ai(stack: ['symfony', 'wordpress'], contractType: ContractType::Freelance, freelance: true, remote: true, recent: true);

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(100, $score);
    }

    public function testComputeScoreIsClampedAtZero(): void
    {
        $job = $this->job('', 'stage alternance débutant');
        $ai = $this->ai();

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(0, $score);
    }

    public function testComputeUnknownContractAddsNoBonus(): void
    {
        $job = $this->job('Développeur', 'Poste à définir');
        $ai = $this->ai();

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(0, $score);
    }

    public function testComputeFreelanceFlagOverridesContractType(): void
    {
        $job = $this->job('Dev', 'Mission');
        $ai = $this->ai(freelance: true);

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(25, $score); // freelance:15 + mission:10
        $this->assertContains('+15 (freelance)', $breakdown);
    }

    // -------------------------------------------------------------------------
    // Bonus séniorité et budget
    // -------------------------------------------------------------------------

    public function testComputeSeniorityBonusForSenior(): void
    {
        $job = $this->job('Développeur PHP', 'Poste confirmé');
        $ai = $this->ai(seniority: Seniority::Senior);

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(35, $score); // php titre:20 + séniorité senior:15
        $this->assertContains('+15 (séniorité senior)', $breakdown);
    }

    public function testComputeSeniorityPenaltyForJunior(): void
    {
        $job = $this->job('Développeur PHP', 'Poste débutant accepté');
        $ai = $this->ai(seniority: Seniority::Junior);

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(10, $score); // php titre:20 - séniorité junior:10
    }

    public function testComputeSeniorityUnknownAddsNoBonus(): void
    {
        $job = $this->job('Développeur PHP', 'Poste à définir');
        $ai = $this->ai();

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(20, $score); // php titre:20 uniquement
        $this->assertEmpty(array_filter($breakdown, static fn (string $line) => str_contains($line, 'séniorité')));
    }

    public function testComputeBudgetBonusForHighDailyRate(): void
    {
        $job = $this->job('Développeur PHP', 'Poste freelance à distance');
        $ai = $this->ai(freelance: true, budget: '600€/j');

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(45, $score); // php titre:20 + freelance:15 + budget TJM:10
        $this->assertContains('+10 (budget TJM)', $breakdown);
    }

    public function testComputeBudgetBonusForHighAnnualSalary(): void
    {
        $job = $this->job('Développeur PHP', 'Poste à distance');
        $ai = $this->ai(contractType: ContractType::Cdi, budget: '60-80k€/an');

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(40, $score); // php titre:20 + cdi:10 + budget annuel (borne haute 80k):10
        $this->assertContains('+10 (budget annuel)', $breakdown);
    }

    public function testComputeNoBudgetBonusBelowThreshold(): void
    {
        $job = $this->job('Développeur PHP', 'Poste freelance');
        $ai = $this->ai(freelance: true, budget: '300€/j');

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(35, $score); // php titre:20 + freelance:15, pas de bonus budget
        $this->assertEmpty(array_filter($breakdown, static fn (string $line) => str_contains($line, 'budget')));
    }

    // -------------------------------------------------------------------------
    // Mots entiers
    // -------------------------------------------------------------------------

    public function testPreScoreIgnoresSubstringMatches(): void
    {
        $job = $this->job('Chargé de commission', 'Capital et backstage, phpstorm');

        $this->assertSame(0, $this->service->preScore($job));
    }

    public function testComputeDescriptionKeywordIgnoresSubstrings(): void
    {
        ['score' => $score] = $this->service->compute($this->job('Dev', 'Commission élevée'), $this->ai());

        $this->assertSame(0, $score); // "mission" ne doit pas matcher "commission"
    }

    // -------------------------------------------------------------------------
    // Pertinence LLM
    // -------------------------------------------------------------------------

    public function testComputeRelevanceBonusAndPenalty(): void
    {
        $job = $this->job('Dev', 'Poste');

        ['score' => $high, 'breakdown' => $breakdown] = $this->service->compute($job, $this->ai(relevance: 100));
        ['score' => $low] = $this->service->compute($this->job('Dev PHP', 'Poste'), $this->ai(relevance: 0));
        ['score' => $neutral] = $this->service->compute($job, $this->ai(relevance: 50));

        $this->assertSame(20, $high);
        $this->assertContains('+20 (pertinence IA 100)', $breakdown);
        $this->assertSame(0, $low); // php titre:20 - 20
        $this->assertSame(0, $neutral);
    }

    public function testComputePenalizesOffTargetLanguage(): void
    {
        $job = $this->job('Développeur Java PHP', 'Poste');
        $ai = $this->ai(primaryLanguage: 'java');

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(0, $score); // php titre:20 - 30 → clampé
        $this->assertContains('-30 (langage principal java hors cible)', $breakdown);
    }

    public function testComputeTargetOrUnknownLanguageIsNotPenalized(): void
    {
        $job = $this->job('Dev', 'Poste');

        $this->assertSame(0, $this->service->compute($job, $this->ai(primaryLanguage: 'php'))['score']);
        $this->assertSame(0, $this->service->compute($job, $this->ai(primaryLanguage: 'unknown', stack: []))['score']);
        $this->assertEmpty(array_filter(
            $this->service->compute($job, $this->ai(primaryLanguage: 'unknown'))['breakdown'],
            static fn (string $line) => str_contains($line, 'langage'),
        ));
    }

    public function testComputeEmptyTargetLanguagesDisablesLanguagePenalty(): void
    {
        $service = new Scoring(['prescore' => [], 'compute' => [
            'title_keywords' => [], 'stack_keywords' => [], 'contract_bonuses' => ['freelance' => 0, 'cdi' => 0],
            'flag_bonuses' => [], 'description_keywords' => [], 'negative_keywords' => [], 'seniority_bonuses' => [],
            'budget_bonus' => ['min_daily_rate' => 500, 'min_annual_salary' => 55, 'points' => 10],
            // CSV vide issu de l'env : [''] — ne doit pas pénaliser tous les langages.
            'relevance' => ['target_languages' => [''], 'off_target_penalty' => -30],
        ]]);

        $result = $service->compute($this->job('Dev', 'Poste'), $this->ai(primaryLanguage: 'java'));

        $this->assertSame([], $result['breakdown']);
    }

    public function testWeightsAcceptEnvStyleKeywordPointsLists(): void
    {
        $service = new Scoring([
            'prescore' => [
                'keywords' => ['golang:20', 'full stack:7', 'sre : 5'],
                'remote_keywords' => [],
                'remote_bonus' => 0,
                'negative_keywords' => ['stage:-50', ''],
            ],
            'compute' => [
                'title_keywords' => ['golang:25'],
                'stack_keywords' => ['kubernetes:+10'],
                'contract_bonuses' => ['freelance' => 0, 'cdi' => 0],
                'flag_bonuses' => [],
                'description_keywords' => [],
                'negative_keywords' => ['stage:-50'],
                'seniority_bonuses' => [],
                'budget_bonus' => ['min_daily_rate' => 500, 'min_annual_salary' => 55, 'points' => 10],
            ],
        ]);

        $this->assertSame(27, $service->preScore($this->job('Développeur Golang', 'Poste full stack')));
        $this->assertSame(-30, $service->preScore($this->job('Stage Golang', 'x')));
        $this->assertSame(35, $service->compute($this->job('Dev Golang', 'x'), $this->ai(stack: ['kubernetes']))['score']);
    }

    #[DataProvider('invalidWeights')]
    public function testInvalidWeightEntryFailsFast(string $entry): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Scoring(['prescore' => ['keywords' => [$entry]], 'compute' => []]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidWeights(): iterable
    {
        yield 'sans points' => ['symfony'];
        yield 'points non numériques' => ['symfony:beaucoup'];
        yield 'mot-clé vide' => [':10'];
    }

    public function testComputeConfigurableTargetLanguagesJavaGolang(): void
    {
        $service = new Scoring(['prescore' => [], 'compute' => [
            'title_keywords' => [], 'stack_keywords' => [], 'contract_bonuses' => ['freelance' => 0, 'cdi' => 0],
            'flag_bonuses' => [], 'description_keywords' => [], 'negative_keywords' => [], 'seniority_bonuses' => [],
            'budget_bonus' => ['min_daily_rate' => 500, 'min_annual_salary' => 55, 'points' => 10],
            'relevance' => ['target_languages' => ['java', 'golang'], 'off_target_penalty' => -30],
        ]]);

        $this->assertSame([], $service->compute($this->job('Dev', 'x'), $this->ai(primaryLanguage: 'golang'))['breakdown']);
        $this->assertContains(
            '-30 (langage principal php hors cible)',
            $service->compute($this->job('Dev', 'x'), $this->ai(primaryLanguage: 'php'))['breakdown'],
        );
    }

    public function testComputePenalizesLocationMismatchOnlyWhenFalse(): void
    {
        $job = $this->job('Développeur PHP', 'Poste');

        $this->assertSame(5, $this->service->compute($job, $this->ai(locationOk: false))['score']); // 20 - 15
        $this->assertSame(20, $this->service->compute($job, $this->ai(locationOk: true))['score']);
        $this->assertSame(20, $this->service->compute($job, $this->ai(locationOk: null))['score']);
    }

    // -------------------------------------------------------------------------

    private function job(string $title, string $description): JobDto
    {
        return new JobDto($title, 'https://example.com', $description, 'test');
    }

    private function ai(
        array $stack = [],
        ContractType $contractType = ContractType::Unknown,
        bool $freelance = false,
        bool $remote = false,
        bool $recent = false,
        string $budget = 'non précisé',
        Seniority $seniority = Seniority::Unknown,
        ?string $primaryLanguage = null,
        ?int $relevance = null,
        ?bool $locationOk = null,
    ): AiAnalysisDto {
        return new AiAnalysisDto($stack, $contractType, $freelance, $remote, $budget, $recent, $seniority, $primaryLanguage, $relevance, $locationOk);
    }
}
