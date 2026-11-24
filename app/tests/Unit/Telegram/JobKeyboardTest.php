<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Telegram\JobKeyboard;
use App\Tracking\JobUserState;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class JobKeyboardTest extends TestCase
{
    private function job(?JobUserState $state = null): Job
    {
        $job = Job::fromDTO(new JobDto('Dév', 'https://jobs.example/1', 'D', 'test'));
        new \ReflectionProperty(Job::class, 'id')->setValue($job, 42);
        $job->setUserState($state, new \DateTimeImmutable());

        return $job;
    }

    /** @return list<string> */
    private static function data(array $keyboard): array
    {
        return array_merge(...array_map(static fn (array $row): array => array_column($row, 'callback_data'), $keyboard['inline_keyboard']));
    }

    public function testANewJobOffersTrackKeepAndIgnore(): void
    {
        self::assertSame(['t42', 's42', 'i42'], self::data(JobKeyboard::forJob($this->job())));
    }

    public function testADecidedJobOffersToUndo(): void
    {
        self::assertSame(['u42'], self::data(JobKeyboard::forJob($this->job(JobUserState::Ignored))));
    }

    public function testATrackedDraftOffersAppliedAndAnAppliedOneOffersNothing(): void
    {
        self::assertSame(['a42'], self::data(JobKeyboard::forJob($this->job(), true, true)));
        self::assertSame([], JobKeyboard::forJob($this->job(), true, false)['inline_keyboard']);
    }

    public function testEveryCallbackStaysWithinTelegramsLimit(): void
    {
        foreach (['t', 's', 'i', 'a', 'u'] as $action) {
            self::assertLessThanOrEqual(64, strlen($action . PHP_INT_MAX));
        }
    }

    /** @return iterable<string, array{string, ?array{string, int}}> */
    public static function callbacks(): iterable
    {
        yield 'suivre' => ['t42', ['t', 42]];
        yield 'ignorer' => ['i7', ['i', 7]];
        yield 'annuler' => ['u1', ['u', 1]];
        yield 'action inconnue' => ['x42', null];
        yield 'sans identifiant' => ['t', null];
        yield 'identifiant négatif' => ['t-4', null];
        yield 'injection' => ['t42; DROP', null];
        yield 'espaces' => [' t42', null];
        yield 'trop long' => ['t1234567890123', null];
        yield 'vide' => ['', null];
    }

    /** @param array{string, int}|null $expected */
    #[DataProvider('callbacks')]
    public function testParsesOnlyWellFormedCallbacks(string $data, ?array $expected): void
    {
        self::assertSame($expected, JobKeyboard::parse($data));
    }
}
