<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Entity\Job;
use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use PHPUnit\Framework\TestCase;
use App\Repository\JobRepository;
use App\Command\ExportJobsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ExportJobsCommandTest extends TestCase
{
    public function testExportsCsvToStdout(): void
    {
        $repository = $this->createStub(JobRepository::class);
        $repository->method('findForExport')->willReturn([$this->job()]);
        $tester = new CommandTester(new ExportJobsCommand($repository));

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'csv']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('id,title,score,source,contract,remote,url,created_at', $display);
        self::assertStringContainsString('42,"Développeur PHP",82,searxng,freelance,1,https://jobs.example/42,', $display);
    }

    public function testExportsJsonToStdout(): void
    {
        $repository = $this->createStub(JobRepository::class);
        $repository->method('findForExport')->willReturn([$this->job()]);
        $tester = new CommandTester(new ExportJobsCommand($repository));

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));

        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $rows);
        self::assertSame('Développeur PHP', $rows[0]['title']);
        self::assertSame('freelance', $rows[0]['contract']);
        self::assertTrue($rows[0]['remote']);
    }

    public function testRejectsUnknownFormat(): void
    {
        $repository = $this->createMock(JobRepository::class);
        $repository->expects($this->never())->method('findForExport');
        $tester = new CommandTester(new ExportJobsCommand($repository));

        self::assertSame(Command::INVALID, $tester->execute(['--format' => 'xml']));
        self::assertStringContainsString('Format invalide', $tester->getDisplay());
    }

    public function testForwardsMinScoreFilterToRepository(): void
    {
        $repository = $this->createMock(JobRepository::class);
        $repository->expects($this->once())
            ->method('findForExport')
            ->with(60)
            ->willReturn([]);
        $tester = new CommandTester(new ExportJobsCommand($repository));

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'csv', '--min-score' => '60']));
    }

    public function testWritesToOutputFile(): void
    {
        $repository = $this->createStub(JobRepository::class);
        $repository->method('findForExport')->willReturn([$this->job()]);
        $path = sys_get_temp_dir() . '/jobscan_export_' . uniqid() . '.json';
        $tester = new CommandTester(new ExportJobsCommand($repository));

        try {
            self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json', '--output' => $path]));
            self::assertFileExists($path);

            /** @var list<array<string, mixed>> $rows */
            $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('Développeur PHP', $rows[0]['title']);
            self::assertStringContainsString('exportée(s) vers', $tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }

    private function job(): Job
    {
        $job = new Job();
        $job->setTitle('Développeur PHP')
            ->setUrl('https://jobs.example/42')
            ->setSource('searxng')
            ->setScore(82);
        $job->setAnalysis(
            new AiAnalysisDto(['php', 'symfony'], ContractType::Freelance, true, true, '500€/j', false, Seniority::Senior),
            [],
        );

        $id = new \ReflectionProperty(Job::class, 'id');
        $id->setValue($job, 42);

        return $job;
    }
}
