<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use App\Command\PurgeJobsCommand;
use App\Repository\JobRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeJobsCommandTest extends TestCase
{
    public function testDeletesJobsOlderThanDefaultThreshold(): void
    {
        $repository = $this->createMock(JobRepository::class);
        $repository->expects($this->once())
            ->method('deleteCreatedBefore')
            ->with($this->callback($this->approximatelyDaysAgo(30)))
            ->willReturn(7);
        $repository->expects($this->never())->method('countCreatedBefore');
        $tester = new CommandTester(new PurgeJobsCommand($repository));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('7 offre(s) supprimée(s)', $tester->getDisplay());
    }

    public function testParsesWeeksUnit(): void
    {
        $repository = $this->createMock(JobRepository::class);
        $repository->expects($this->once())
            ->method('deleteCreatedBefore')
            ->with($this->callback($this->approximatelyDaysAgo(28)))
            ->willReturn(0);
        $tester = new CommandTester(new PurgeJobsCommand($repository));

        self::assertSame(Command::SUCCESS, $tester->execute(['--older-than' => '4w']));
    }

    public function testDryRunCountsWithoutDeleting(): void
    {
        $repository = $this->createMock(JobRepository::class);
        $repository->expects($this->once())
            ->method('countCreatedBefore')
            ->willReturn(12);
        $repository->expects($this->never())->method('deleteCreatedBefore');
        $tester = new CommandTester(new PurgeJobsCommand($repository));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('dry-run', $display);
        self::assertStringContainsString('12 offre(s)', $display);
    }

    public function testRejectsInvalidOlderThanExpression(): void
    {
        $repository = $this->createMock(JobRepository::class);
        $repository->expects($this->never())->method('deleteCreatedBefore');
        $repository->expects($this->never())->method('countCreatedBefore');
        $tester = new CommandTester(new PurgeJobsCommand($repository));

        self::assertSame(Command::INVALID, $tester->execute(['--older-than' => 'last month']));
        self::assertStringContainsString('--older-than invalide', $tester->getDisplay());
    }

    /**
     * @return \Closure(mixed): bool
     */
    private function approximatelyDaysAgo(int $days): \Closure
    {
        return static function (mixed $value) use ($days): bool {
            if (!$value instanceof \DateTimeImmutable) {
                return false;
            }

            $expected = new \DateTimeImmutable(sprintf('-%d days', $days))->getTimestamp();

            return abs($value->getTimestamp() - $expected) <= 5;
        };
    }
}
