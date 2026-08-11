<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\DTO\JobDto;
use PHPUnit\Framework\TestCase;
use App\Command\RunPipelineCommand;
use App\Provider\JobProviderInterface;
use App\Processor\JobProcessingResult;
use App\Processor\JobProcessingStatus;
use App\Processor\JobProcessorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RunPipelineCommandTest extends TestCase
{
    public function testDryRunPassesFlagAndDisplaysSummary(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->once())
            ->method('process')
            ->with($this->isInstanceOf(JobDto::class), true)
            ->willReturn(new JobProcessingResult(JobProcessingStatus::DryRun, 75));
        $provider = $this->provider('remoteok', [new JobDto('PHP', 'https://example.test/1', 'PHP', 'test')]);
        $tester = new CommandTester(new RunPipelineCommand([$provider], $processor));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Mode dry-run', $tester->getDisplay());
        self::assertStringContainsString('Analysées par IA', $tester->getDisplay());
    }

    public function testFiltersProviders(): void
    {
        $processor = $this->createMock(JobProcessorInterface::class);
        $processor->expects($this->once())
            ->method('process')
            ->willReturn(new JobProcessingResult(JobProcessingStatus::Filtered));
        $remoteOk = $this->provider('remoteok', [new JobDto('PHP', 'https://example.test/1', 'PHP', 'test')]);
        $rss = $this->provider('rss', [new JobDto('PHP', 'https://example.test/2', 'PHP', 'test')]);
        $tester = new CommandTester(new RunPipelineCommand([$remoteOk, $rss], $processor));

        self::assertSame(Command::SUCCESS, $tester->execute(['--provider' => ['rss']]));
        self::assertStringNotContainsString('remoteok', strtolower($tester->getDisplay()));
    }

    public function testRejectsUnknownProvider(): void
    {
        $processor = $this->createStub(JobProcessorInterface::class);
        $tester = new CommandTester(new RunPipelineCommand([$this->provider('rss', [])], $processor));

        self::assertSame(Command::INVALID, $tester->execute(['--provider' => ['unknown']]));
        self::assertStringContainsString('Provider(s) inconnu(s)', $tester->getDisplay());
    }

    /** @param JobDto[] $jobs */
    private function provider(string $name, array $jobs): JobProviderInterface
    {
        return new readonly class($name, $jobs) implements JobProviderInterface {
            /** @param JobDto[] $jobs */
            public function __construct(private string $providerName, private array $jobs)
            {
            }

            public function name(): string
            {
                return $this->providerName;
            }

            public function fetch(): array
            {
                return $this->jobs;
            }
        };
    }
}
