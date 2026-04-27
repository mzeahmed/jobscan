<?php

declare(strict_types=1);

namespace App\Tests\Service\Scoring;

use App\DTO\JobDTO;
use App\Service\Scoring\ScoringService;
use PHPUnit\Framework\TestCase;

class ScoringServiceTest extends TestCase
{
    private ScoringService $service;

    protected function setUp(): void
    {
        $this->service = new ScoringService([
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
        $ai = [
            'stack' => ['php', 'symfony'],
            'contract_type' => 'freelance',
            'freelance' => true,
            'remote' => true,
            'recent' => true,
        ];

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
        $ai = [
            'stack' => ['wordpress', 'php'],
            'contract_type' => 'cdi',
            'freelance' => false,
            'remote' => false,
            'recent' => false,
        ];

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(30, $score); // wordpress stack:20 + cdi:10
    }

    public function testComputeDescriptionKeywordsUrgentAndMission(): void
    {
        $job = $this->job('Développeur PHP', 'Mission urgent à pourvoir');
        $ai = ['stack' => [], 'contract_type' => 'unknown', 'freelance' => false, 'remote' => false, 'recent' => false];

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(45, $score); // php titre:20 + mission:10 + urgent:15
        $this->assertContains('+10 (mission)', $breakdown);
        $this->assertContains('+15 (urgent)', $breakdown);
    }

    public function testComputePenalizesStage(): void
    {
        $job = $this->job('Développeur PHP', 'Offre de stage symfony');
        $ai = ['stack' => ['symfony'], 'contract_type' => 'unknown', 'freelance' => false, 'remote' => false, 'recent' => false];

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(0, $score); // php:20 + symfony:30 + stage:-50 = 0 (clamped)
    }

    public function testComputeScoreIsClampedAt100(): void
    {
        $job = $this->job('php developer', 'mission urgent asap');
        $ai = [
            'stack' => ['symfony', 'wordpress'],
            'contract_type' => 'freelance',
            'freelance' => true,
            'remote' => true,
            'recent' => true,
        ];

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(100, $score);
    }

    public function testComputeScoreIsClampedAtZero(): void
    {
        $job = $this->job('', 'stage alternance débutant');
        $ai = ['stack' => [], 'contract_type' => 'unknown', 'freelance' => false, 'remote' => false, 'recent' => false];

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(0, $score);
    }

    public function testComputeUnknownContractAddsNoBonus(): void
    {
        $job = $this->job('Développeur', 'Poste à définir');
        $ai = ['stack' => [], 'contract_type' => 'unknown', 'freelance' => false, 'remote' => false, 'recent' => false];

        ['score' => $score] = $this->service->compute($job, $ai);

        $this->assertSame(0, $score);
    }

    public function testComputeFreelanceFlagOverridesContractType(): void
    {
        $job = $this->job('Dev', 'Mission');
        $ai = ['stack' => [], 'contract_type' => 'unknown', 'freelance' => true, 'remote' => false, 'recent' => false];

        ['score' => $score, 'breakdown' => $breakdown] = $this->service->compute($job, $ai);

        $this->assertSame(25, $score); // freelance:15 + mission:10
        $this->assertContains('+15 (freelance)', $breakdown);
    }

    // -------------------------------------------------------------------------

    private function job(string $title, string $description): JobDTO
    {
        return new JobDTO($title, 'https://example.com', $description, 'test');
    }
}