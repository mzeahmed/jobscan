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

    public function testOutdatedReextractsWithoutRunningTheOrdinaryAnalysis(): void
    {
        $runner = $this->createMock(PendingAnalysisRunnerInterface::class);
        $runner->expects($this->never())->method('run');
        $runner->expects($this->once())->method('reextractOutdated')->with(20)->willReturn(new AnalysisSummary(analyzedByAi: 12, failed: 1, skipped: 2));
        $tester = new CommandTester(new AnalyzeJobsCommand($runner));

        self::assertSame(Command::SUCCESS, $tester->execute(['--outdated' => true, '--limit' => '20']));
        self::assertStringContainsString('Exigences mises à jour', $tester->getDisplay());
        self::assertStringContainsString('LLM indisponible', $tester->getDisplay());
    }

    public function testOutdatedSaysWhenEverythingIsUpToDate(): void
    {
        $runner = $this->createStub(PendingAnalysisRunnerInterface::class);
        $runner->method('reextractOutdated')->willReturn(new AnalysisSummary());
        $tester = new CommandTester(new AnalyzeJobsCommand($runner));

        self::assertSame(Command::SUCCESS, $tester->execute(['--outdated' => true]));
        self::assertStringContainsString('suivent le schéma courant', $tester->getDisplay());
    }
}
