<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use App\Notification\TelegramNotifier;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelegramNotifierTest extends TestCase
{
    public function testReturnsTrueOnlyWhenTelegramConfirmsDelivery(): void
    {
        $client = new MockHttpClient(new MockResponse('{"ok":true}', ['http_code' => 200]));
        $notifier = new TelegramNotifier($client, new NullLogger(), 'token', 'chat', 1, 0);

        self::assertTrue($notifier->send('message'));
    }

    public function testRetriesAndReturnsFalseAfterFailures(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"ok":false,"description":"temporary"}', ['http_code' => 500]);
        });
        $notifier = new TelegramNotifier($client, new NullLogger(), 'token', 'chat', 3, 0);

        self::assertFalse($notifier->send('message'));
        self::assertSame(3, $requests);
    }

    public function testDoesNotRetryPermanentClientError(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(
                '{"ok":false,"description":"Bad Request: can not parse entities"}',
                ['http_code' => 400],
            );
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('abandonnée'),
                ['attempts' => 1],
            );
        $notifier = new TelegramNotifier($client, $logger, 'token', 'chat', 3, 0);

        self::assertFalse($notifier->send('message'));
        self::assertSame(1, $requests);
    }

    public function testRetriesRateLimitAndUsesRetryAfter(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return $requests === 1
                ? new MockResponse(
                    '{"ok":false,"parameters":{"retry_after":0}}',
                    ['http_code' => 429],
                )
                : new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $notifier = new TelegramNotifier($client, new NullLogger(), 'token', 'chat', 3, 250);

        self::assertTrue($notifier->send('message'));
        self::assertSame(2, $requests);
    }
}
