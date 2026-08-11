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

    public function testEscapesExternalValuesInHtmlMessage(): void
    {
        $payload = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$payload): MockResponse {
            $payload = json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR);

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $telegram = new TelegramNotifier($client, new NullLogger(), 'token', 'chat', 1, 0);
        $job = Job::fromDTO(new JobDto(
            'PHP_Symfony & <SEO> "Senior"',
            'https://example.test/job?a=1&b="two"',
            'Description',
            'test',
        ));
        $job->setScore(80);

        self::assertTrue(new Notifier($telegram, new NullLogger(), 60)->notify($job));
        self::assertSame('HTML', $payload['parse_mode']);
        self::assertStringContainsString('PHP_Symfony &amp; &lt;SEO&gt; &quot;Senior&quot;', $payload['text']);
        self::assertStringContainsString('a=1&amp;b=&quot;two&quot;', $payload['text']);
        self::assertStringContainsString('<a href=', $payload['text']);
    }

    private function jobWithScore(int $score): Job
    {
        $job = Job::fromDTO(new JobDto('Développeur PHP', 'https://example.test/job', 'Description', 'test'));
        $job->setScore($score);

        return $job;
    }
}
