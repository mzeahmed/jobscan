<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\DTO\JobDto;
use App\Entity\ProviderRun;
use PHPUnit\Framework\TestCase;
use App\Analysis\AnalysisSummary;
use App\Repository\JobRepository;
use App\Command\RunPipelineCommand;
use App\Monitoring\FetchDiagnostics;
use App\Monitoring\ProviderRunStatus;
use App\Processor\JobProcessingResult;
use App\Processor\JobProcessingStatus;
use App\Provider\JobProviderInterface;
use App\Processor\JobProcessorInterface;
use App\Repository\ProviderRunRepository;
use Symfony\Component\Console\Command\Command;
use App\Analysis\PendingAnalysisRunnerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class RunPipelineCommandTest extends TestCase
{
    public function testDryRunPassesFlagAndDisplaysSummary(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->once())
            ->method('processBatch')
            ->with($this->isArray(), true)
            ->willReturn([new JobProcessingResult(JobProcessingStatus::DryRun, 75)]);
        $provider = $this->provider('remoteok', [new JobDto('PHP', 'https://example.test/1', 'PHP', 'test')]);
        $tester = new CommandTester($this->command([$provider], $processor));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Mode dry-run', $tester->getDisplay());
        self::assertStringContainsString('Analysées par IA', $tester->getDisplay());
    }

    public function testFiltersProviders(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->once())
            ->method('processBatch')
            ->willReturn([new JobProcessingResult(JobProcessingStatus::Filtered)]);
        $remoteOk = $this->provider('remoteok', [new JobDto('PHP', 'https://example.test/1', 'PHP', 'test')]);
        $rss = $this->provider('rss', [new JobDto('PHP', 'https://example.test/2', 'PHP', 'test')]);
        $tester = new CommandTester($this->command([$remoteOk, $rss], $processor));

        self::assertSame(Command::SUCCESS, $tester->execute(['--provider' => ['rss']]));
        self::assertStringNotContainsString('remoteok', strtolower($tester->getDisplay()));
    }

    public function testSkipsUnhealthyProvidersButKeepsRunning(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->once())
            ->method('processBatch')
            ->willReturn([new JobProcessingResult(JobProcessingStatus::Saved, 70)]);
        $healthy = $this->provider('rss', [new JobDto('PHP', 'https://example.test/1', 'PHP', 'test')]);
        $down = $this->provider('searxng', [new JobDto('PHP', 'https://example.test/2', 'PHP', 'test')], healthy: false);
        $tester = new CommandTester($this->command([$down, $healthy], $processor));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Provider "searxng" indisponible', $tester->getDisplay());
    }

    public function testSkipHealthCheckOptionBypassesTheProbe(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->once())
            ->method('processBatch')
            ->willReturn([new JobProcessingResult(JobProcessingStatus::Saved, 70)]);
        $down = $this->provider('searxng', [new JobDto('PHP', 'https://example.test/2', 'PHP', 'test')], healthy: false);
        $tester = new CommandTester($this->command([$down], $processor));

        self::assertSame(Command::SUCCESS, $tester->execute(['--skip-health-check' => true]));
        self::assertStringNotContainsString('indisponible', $tester->getDisplay());
    }

    public function testRejectsUnknownProvider(): void
    {
        $processor = $this->createStub(JobProcessorInterface::class);
        $repository = $this->createMock(JobRepository::class);
        $repository->expects($this->never())->method('truncate');
        $tester = new CommandTester($this->command([$this->provider('rss', [])], $processor, $repository));

        self::assertSame(Command::INVALID, $tester->execute(['--provider' => ['unknown']]));
        self::assertStringContainsString('Provider(s) inconnu(s)', $tester->getDisplay());
    }

    public function testResetTruncatesBeforeFetching(): void
    {
        $processor = $this->createStub(JobProcessorInterface::class);
        $repository = $this->createMock(JobRepository::class);
        $repository->expects($this->once())->method('truncate')->willReturn(12);
        $tester = new CommandTester($this->command([$this->provider('rss', [])], $processor, $repository));

        self::assertSame(Command::SUCCESS, $tester->execute(['--reset' => true]));
        self::assertStringContainsString('12 offre(s) supprimée(s)', $tester->getDisplay());
    }

    public function testRejectsResetWithDryRun(): void
    {
        $processor = $this->createStub(JobProcessorInterface::class);
        $repository = $this->createMock(JobRepository::class);
        $repository->expects($this->never())->method('truncate');
        $tester = new CommandTester($this->command([], $processor, $repository));

        self::assertSame(Command::INVALID, $tester->execute(['--reset' => true, '--dry-run' => true]));
    }

    public function testRejectsResetInProduction(): void
    {
        $processor = $this->createStub(JobProcessorInterface::class);
        $repository = $this->createMock(JobRepository::class);
        $repository->expects($this->never())->method('truncate');
        $tester = new CommandTester($this->command([], $processor, $repository, 'prod'));

        self::assertSame(Command::FAILURE, $tester->execute(['--reset' => true]));
        self::assertStringContainsString('production', $tester->getDisplay());
    }

    public function testRecordsAProviderRunWithItsOutcome(): void
    {
        $processor = $this->createStub(JobProcessorInterface::class);
        $processor->method('processBatch')->willReturn([
            new JobProcessingResult(JobProcessingStatus::Saved),
            new JobProcessingResult(JobProcessingStatus::Duplicate),
            new JobProcessingResult(JobProcessingStatus::Duplicate),
        ]);
        $runs = $this->createMock(ProviderRunRepository::class);
        $runs->expects($this->once())->method('save')->with($this->callback(
            static fn (ProviderRun $run): bool => $run->getProvider() === 'rss'
                && $run->getStatus() === ProviderRunStatus::Succeeded
                && $run->getFetchedCount() === 3
                && $run->getNewCount() === 1
                && $run->getDuplicateCount() === 2
                && $run->getErrorCount() === 0,
        ));
        $jobs = array_map(static fn (int $i): JobDto => new JobDto('PHP', 'https://example.test/' . $i, 'PHP', 'test'), [1, 2, 3]);
        $tester = new CommandTester($this->command([$this->provider('rss', $jobs)], $processor, runs: $runs));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testClassifiesAPartialRunFromProviderDiagnostics(): void
    {
        $diagnostics = new FetchDiagnostics();
        $processor = $this->createStub(JobProcessorInterface::class);
        $processor->method('processBatch')->willReturn([new JobProcessingResult(JobProcessingStatus::Saved)]);
        $runs = $this->createMock(ProviderRunRepository::class);
        $runs->expects($this->once())->method('save')->with($this->callback(
            static fn (ProviderRun $run): bool => $run->getStatus() === ProviderRunStatus::Partial
                && $run->getErrorCount() === 1
                && $run->getLastError() === 'flux 2 en timeout',
        ));
        $provider = $this->provider('rss', [new JobDto('PHP', 'https://example.test/1', 'PHP', 'test')], onFetch: static function () use ($diagnostics): void {
            $diagnostics->record('rss', 'flux 2 en timeout');
        });
        $tester = new CommandTester($this->command([$provider], $processor, runs: $runs, diagnostics: $diagnostics));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testRecordsAFailedRunForAnUnhealthyProviderAndKeepsGoing(): void
    {
        $processor = $this->createStub(JobProcessorInterface::class);
        $processor->method('processBatch')->willReturn([new JobProcessingResult(JobProcessingStatus::Saved)]);
        $saved = [];
        $runs = $this->createStub(ProviderRunRepository::class);
        $runs->method('save')->willReturnCallback(static function (ProviderRun $run) use (&$saved): void {
            $saved[$run->getProvider()] = $run->getStatus();
        });
        $down = $this->provider('searxng', [], healthy: false);
        $up = $this->provider('rss', [new JobDto('PHP', 'https://example.test/1', 'PHP', 'test')]);
        $tester = new CommandTester($this->command([$down, $up], $processor, runs: $runs));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(ProviderRunStatus::Failed, $saved['searxng']);
        self::assertSame(ProviderRunStatus::Succeeded, $saved['rss']);
    }

    public function testDryRunRecordsNothingAndSkipsAnalysis(): void
    {
        $processor = $this->createStub(JobProcessorInterface::class);
        $processor->method('processBatch')->willReturn([new JobProcessingResult(JobProcessingStatus::DryRun, 70)]);
        $runs = $this->createMock(ProviderRunRepository::class);
        $runs->expects($this->never())->method('save');
        $analysis = $this->createMock(PendingAnalysisRunnerInterface::class);
        $analysis->expects($this->never())->method('run');
        $provider = $this->provider('rss', [new JobDto('PHP', 'https://example.test/1', 'PHP', 'test')]);
        $tester = new CommandTester($this->command([$provider], $processor, runs: $runs, analysis: $analysis));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
    }

    public function testAcknowledgesIngestionAwareProvidersAfterIngestionButNeverInDryRun(): void
    {
        $results = [new JobProcessingResult(JobProcessingStatus::Saved)];
        $processor = $this->createStub(JobProcessorInterface::class);
        $processor->method('processBatch')->willReturn($results);
        $jobs = [new JobDto('PHP', 'https://example.test/1', 'PHP', 'test')];
        $provider = new class ($jobs) implements JobProviderInterface, \App\Provider\IngestionAwareProviderInterface {
            /** @var list<array{list<JobDto>, list<JobProcessingResult>}> */
            public array $acknowledged = [];

            /** @param list<JobDto> $jobs */
            public function __construct(private readonly array $jobs)
            {
            }

            public function name(): string
            {
                return 'email';
            }

            public function isHealthy(): bool
            {
                return true;
            }

            public function fetch(): array
            {
                return $this->jobs;
            }

            public function acknowledge(array $jobs, array $results): void
            {
                $this->acknowledged[] = [$jobs, $results];
            }
        };

        $tester = new CommandTester($this->command([$provider], $processor));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertCount(1, $provider->acknowledged);
        self::assertSame($jobs, $provider->acknowledged[0][0]);
        self::assertSame($results, $provider->acknowledged[0][1]);

        $tester->execute(['--dry-run' => true]);
        self::assertCount(1, $provider->acknowledged, 'Le dry-run ne marque rien comme traité.');
    }

    public function testAnalyzesPendingJobsAfterCollectionUnlessDisabled(): void
    {
        $processor = $this->createStub(JobProcessorInterface::class);
        $processor->method('processBatch')->willReturn([new JobProcessingResult(JobProcessingStatus::Saved)]);
        $analysis = $this->createMock(PendingAnalysisRunnerInterface::class);
        $analysis->expects($this->once())->method('run')->with(50)->willReturn(new AnalysisSummary(2, 1, 0, 1));
        $provider = $this->provider('rss', [new JobDto('PHP', 'https://example.test/1', 'PHP', 'test')]);
        $tester = new CommandTester($this->command([$provider], $processor, analysis: $analysis));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('3 offre(s) traitée(s) (2 IA, 1 heuristique, 0 en échec)', $tester->getDisplay());

        $skipped = $this->createMock(PendingAnalysisRunnerInterface::class);
        $skipped->expects($this->never())->method('run');
        $tester = new CommandTester($this->command([$provider], $processor, analysis: $skipped));
        self::assertSame(Command::SUCCESS, $tester->execute(['--no-analyze' => true]));
    }

    /**
     * @param iterable<JobProviderInterface> $providers
     */
    private function command(
        iterable $providers,
        JobProcessorInterface $processor,
        ?JobRepository $repository = null,
        string $environment = 'test',
        ?ProviderRunRepository $runs = null,
        ?PendingAnalysisRunnerInterface $analysis = null,
        ?FetchDiagnostics $diagnostics = null,
    ): RunPipelineCommand {
        if ($analysis === null) {
            $analysis = $this->createStub(PendingAnalysisRunnerInterface::class);
            $analysis->method('run')->willReturn(new AnalysisSummary());
        }

        return new RunPipelineCommand(
            $providers,
            $processor,
            $repository ?? $this->createStub(JobRepository::class),
            $environment,
            $analysis,
            $runs ?? $this->createStub(ProviderRunRepository::class),
            $diagnostics ?? new FetchDiagnostics(),
            50,
        );
    }

    /** @param JobDto[] $jobs */
    private function provider(string $name, array $jobs, bool $healthy = true, ?\Closure $onFetch = null): JobProviderInterface
    {
        return new readonly class ($name, $jobs, $healthy, $onFetch) implements JobProviderInterface {
            /** @param JobDto[] $jobs */
            public function __construct(private string $providerName, private array $jobs, private bool $healthy, private ?\Closure $onFetch)
            {
            }

            public function name(): string
            {
                return $this->providerName;
            }

            public function isHealthy(): bool
            {
                return $this->healthy;
            }

            public function fetch(): array
            {
                if ($this->onFetch !== null) {
                    ($this->onFetch)();
                }

                return $this->jobs;
            }
        };
    }
}
