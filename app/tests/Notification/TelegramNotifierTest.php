<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use Psr\Log\NullLogger;
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
}
