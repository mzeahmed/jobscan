<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use App\Analysis\AnalysisSummary;
use App\Command\AnalyzeJobsCommand;
use Symfony\Component\Console\Command\Command;
use App\Analysis\PendingAnalysisRunnerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyzeJobsCommandTest extends TestCase
{
    public function testRunsTheAnalysisWithTheRequestedLimit(): void
    {
        $runner = $this->createMock(PendingAnalysisRunnerInterface::class);
        $runner->expects($this->once())->method('run')->with(5)->willReturn(new AnalysisSummary(3, 1, 1, 2));
        $tester = new CommandTester(new AnalyzeJobsCommand($runner));

        self::assertSame(Command::SUCCESS, $tester->execute(['--limit' => '5']));
        self::assertStringContainsString('Notifiées', $tester->getDisplay());
    }

    public function testUsesTheConfiguredDefaultLimitAndRejectsInvalidOnes(): void
    {
        $runner = $this->createMock(PendingAnalysisRunnerInterface::class);
        $runner->expects($this->once())->method('run')->with(50)->willReturn(new AnalysisSummary());
        $tester = new CommandTester(new AnalyzeJobsCommand($runner, 50));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Aucune offre à analyser', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '0']));
    }
}
