<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\DTO\JobDto;
use App\Entity\Job;
use App\DTO\Seniority;
use Psr\Log\NullLogger;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
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
        $this->recordAnalysis($job, 80, false);

        self::assertTrue(new Notifier($telegram, new NullLogger(), 60)->notify($job));
        self::assertSame('HTML', $payload['parse_mode']);
        self::assertStringContainsString('PHP_Symfony &amp; &lt;SEO&gt; &quot;Senior&quot;', $payload['text']);
        self::assertStringContainsString('a=1&amp;b=&quot;two&quot;', $payload['text']);
        self::assertStringContainsString('<a href=', $payload['text']);
    }

    public function testDoesNotNotifyAJobThatIsNotAnalyzedYet(): void
    {
        $payloads = 0;
        $client = new MockHttpClient(static function () use (&$payloads): MockResponse {
            ++$payloads;

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $job = Job::fromDTO(new JobDto('Développeur PHP', 'https://example.test/job', 'Description', 'test'));

        $notified = new Notifier($this->telegram($client), new NullLogger(), 0)->notify($job);

        self::assertFalse($notified);
        self::assertSame(0, $payloads);
    }

    public function testWaitsForTheLlmWhileAHeuristicAnalysisCanStillBeRetried(): void
    {
        $now = new \DateTimeImmutable();
        $retryable = $this->jobWithScore(80, true, $now->modify('+15 minutes'));
        $exhausted = $this->jobWithScore(80, true);
        $notifier = new Notifier(
            $this->telegram(new MockHttpClient(new MockResponse('{"ok":true}'))),
            new NullLogger(),
            60,
        );

        self::assertFalse($notifier->notify($retryable));
        self::assertTrue($notifier->notify($exhausted));
        self::assertNull($retryable->getNotifiedAt());
    }

    public function testDoesNotNotifyAProbableDuplicate(): void
    {
        $job = $this->jobWithScore(90);
        $job->markProbableDuplicateOf(12);

        self::assertFalse(new Notifier($this->telegram(new MockHttpClient(new MockResponse('{"ok":true}'))), new NullLogger(), 60)->notify($job));
    }

    private function telegram(MockHttpClient $client): TelegramNotifier
    {
        return new TelegramNotifier($client, new NullLogger(), 'token', 'chat', 1, 0);
    }

    private function jobWithScore(int $score, bool $fallback = false, ?\DateTimeImmutable $nextAttemptAt = null): Job
    {
        $job = Job::fromDTO(new JobDto('Développeur PHP', 'https://example.test/job', 'Description', 'test'));
        $this->recordAnalysis($job, $score, $fallback, $nextAttemptAt);

        return $job;
    }

    private function recordAnalysis(Job $job, int $score, bool $fallback, ?\DateTimeImmutable $nextAttemptAt = null): void
    {
        $job->recordAnalysis(
            new AiAnalysisDto(['php'], ContractType::Unknown, false, false, 'non précisé', false, Seniority::Unknown),
            $score,
            [],
            $fallback,
            new \DateTimeImmutable(),
            $nextAttemptAt,
        );
    }
}
