<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\DTO\JobDto;
use App\Entity\Job;
use Psr\Log\NullLogger;
use App\Notification\Notifier;
use PHPUnit\Framework\TestCase;
use App\Notification\TelegramNotifier;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NotifierTest extends TestCase
{
    public function testDoesNotMarkJobWhenTelegramFails(): void
    {
        $telegram = new TelegramNotifier(
            new MockHttpClient(new MockResponse('{"ok":false}', ['http_code' => 500])),
            new NullLogger(),
            'token',
            'chat',
            1,
            0,
        );
        $job = $this->jobWithScore(80);

        $notified = new Notifier($telegram, new NullLogger(), 60)->notify($job);

        self::assertFalse($notified);
        self::assertNull($job->getNotifiedAt());
    }

    public function testMarksJobAfterTelegramConfirmsDelivery(): void
    {
        $telegram = new TelegramNotifier(
            new MockHttpClient(new MockResponse('{"ok":true}', ['http_code' => 200])),
            new NullLogger(),
            'token',
            'chat',
            1,
            0,
        );
        $job = $this->jobWithScore(80);

        $notified = new Notifier($telegram, new NullLogger(), 60)->notify($job);

        self::assertTrue($notified);
        self::assertNotNull($job->getNotifiedAt());
    }

    private function jobWithScore(int $score): Job
    {
        $job = Job::fromDTO(new JobDto('Développeur PHP', 'https://example.test/job', 'Description', 'test'));
        $job->setScore($score);

        return $job;
    }
}
