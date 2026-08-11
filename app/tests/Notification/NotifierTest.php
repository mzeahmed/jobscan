<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\DTO\JobDto;
use App\Entity\Job;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\Notification\Notifier;
use Doctrine\ORM\EntityManagerInterface;
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
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');
        $job = $this->jobWithScore(80);

        (new Notifier($telegram, new NullLogger(), $entityManager, 60))->notify($job);

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
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $job = $this->jobWithScore(80);

        (new Notifier($telegram, new NullLogger(), $entityManager, 60))->notify($job);

        self::assertNotNull($job->getNotifiedAt());
    }

    private function jobWithScore(int $score): Job
    {
        $job = Job::fromDTO(new JobDto('Développeur PHP', 'https://example.test/job', 'Description', 'test'));
        $job->setScore($score);

        return $job;
    }
}
