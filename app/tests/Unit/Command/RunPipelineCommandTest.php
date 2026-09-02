<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\DTO\JobDto;
use PHPUnit\Framework\TestCase;
use App\Repository\JobRepository;
use App\Command\RunPipelineCommand;
use App\Processor\JobProcessingResult;
use App\Processor\JobProcessingStatus;
use App\Provider\JobProviderInterface;
use App\Processor\JobProcessorInterface;
use Symfony\Component\Console\Command\Command;
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

    /**
     * @param iterable<JobProviderInterface> $providers
     */
    private function command(
        iterable $providers,
        JobProcessorInterface $processor,
        ?JobRepository $repository = null,
        string $environment = 'test',
    ): RunPipelineCommand {
        return new RunPipelineCommand(
            $providers,
            $processor,
            $repository ?? $this->createStub(JobRepository::class),
            $environment,
        );
    }

    /** @param JobDto[] $jobs */
    private function provider(string $name, array $jobs, bool $healthy = true): JobProviderInterface
    {
        return new readonly class ($name, $jobs, $healthy) implements JobProviderInterface {
            /** @param JobDto[] $jobs */
            public function __construct(private string $providerName, private array $jobs, private bool $healthy)
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
                return $this->jobs;
            }
        };
    }
}
