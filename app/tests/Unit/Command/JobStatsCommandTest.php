<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Entity\ProviderRun;
use PHPUnit\Framework\TestCase;
use App\Command\JobStatsCommand;
use App\Repository\JobRepository;
use App\Monitoring\ProviderRunStatus;
use App\Repository\ProviderRunRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class JobStatsCommandTest extends TestCase
{
    public function testDisplaysGlobalAndSourceStatistics(): void
    {
        $repository = $this->createStub(JobRepository::class);
        $repository->method('countAll')->willReturn(12);
        $repository->method('countToday')->willReturn(3);
        $repository->method('countNotified')->willReturn(2);
        $repository->method('averageScore')->willReturn(67.5);
        $repository->method('countByScoreRange')->willReturnMap([
            [80, null, 2],
            [60, 79, 5],
            [null, 59, 5],
        ]);
        $repository->method('countBySource')->willReturn(['remoteok' => 8, 'searxng' => 4]);
        $repository->method('countByAnalysisStatus')->willReturn(['done' => 9, 'pending' => 3]);
        $runs = $this->createStub(ProviderRunRepository::class);
        $runs->method('findLatestByProvider')->willReturn([
            'remoteok' => new ProviderRun('remoteok', new \DateTimeImmutable('2026-11-24 10:00'), 1500, ProviderRunStatus::Partial, 10, 2, 8, 1, 'timeout sur le flux 2'),
        ]);
        $runs->method('findLastSuccessByProvider')->willReturn(['remoteok' => new \DateTimeImmutable('2026-11-23 10:00')]);
        $tester = new CommandTester(new JobStatsCommand($repository, $runs));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('67,5', $tester->getDisplay());
        self::assertStringContainsString('remoteok', $tester->getDisplay());
        self::assertStringContainsString('searxng', $tester->getDisplay());
        self::assertStringContainsString('pending', $tester->getDisplay());
        self::assertStringContainsString('partial', $tester->getDisplay());
        self::assertStringContainsString('2026-11-23 10:00', $tester->getDisplay());
        self::assertStringContainsString('timeout sur le flux 2', $tester->getDisplay());
    }
}
