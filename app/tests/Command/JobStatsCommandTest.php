<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;
use App\Command\JobStatsCommand;
use App\Repository\JobRepository;
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
        $tester = new CommandTester(new JobStatsCommand($repository));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('67,5', $tester->getDisplay());
        self::assertStringContainsString('remoteok', $tester->getDisplay());
        self::assertStringContainsString('searxng', $tester->getDisplay());
    }
}
