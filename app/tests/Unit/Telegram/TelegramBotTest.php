<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use Psr\Log\NullLogger;
use App\Telegram\OffsetStore;
use App\Telegram\TelegramBot;
use PHPUnit\Framework\TestCase;
use App\Notification\TelegramNotifier;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelegramBotTest extends TestCase
{
    private const string TOKEN = '123456:SECRET-TOKEN';

    /** @var list<array{string, array<string, mixed>}> */
    private array $calls = [];

    private function bot(string $body = '{"ok":true,"result":[]}', int $status = 200): TelegramBot
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($body, $status): MockResponse {
            $this->calls[] = [$url, json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR)];

            return new MockResponse($body, ['http_code' => $status]);
        });

        return new TelegramBot($client, new NullLogger(), self::TOKEN);
    }

    public function testGetUpdatesAsksOnlyForMessagesAndButtons(): void
    {
        $updates = $this->bot('{"ok":true,"result":[{"update_id":5},"bizarre",{"update_id":6}]}')->getUpdates(5, 25);

        self::assertSame([['update_id' => 5], ['update_id' => 6]], $updates);
        self::assertSame('https://api.telegram.org/bot' . self::TOKEN . '/getUpdates', $this->calls[0][0]);
        self::assertSame(['message', 'callback_query'], $this->calls[0][1]['allowed_updates']);
        self::assertSame(5, $this->calls[0][1]['offset']);
    }

    public function testFailuresGiveNullInsteadOfExceptions(): void
    {
        self::assertNull($this->bot('{"ok":false,"description":"Unauthorized"}', 401)->getUpdates(0, 0));
        self::assertNull($this->bot('pas du json')->getUpdates(0, 0));
        self::assertFalse($this->bot('{"ok":false}')->answerCallback('id', 'x'));
        self::assertTrue($this->bot('{"ok":true}')->answerCallback('id', 'x'));
        self::assertFalse($this->bot('{"ok":false}')->editKeyboard(1, 2, []));
    }

    public function testTheBotTokenNeverReachesTheLogs(): void
    {
        $messages = [];
        $logger = new class ($messages) extends \Psr\Log\AbstractLogger {
            /** @param list<string> $messages */
            public function __construct(public array &$messages)
            {
            }

            public function log($level, \Stringable | string $message, array $context = []): void
            {
                $this->messages[] = $message . json_encode($context);
            }
        };
        $failing = new MockHttpClient(static fn () => throw new \RuntimeException('Idle timeout for "https://api.telegram.org/bot' . self::TOKEN . '/getUpdates"'));

        new TelegramBot($failing, $logger, self::TOKEN)->getUpdates(0, 0);
        new TelegramNotifier($failing, $logger, self::TOKEN, '1', 1, 0)->send('x');

        self::assertNotSame([], $messages);
        self::assertStringNotContainsString('SECRET-TOKEN', implode("\n", $messages));
        self::assertStringContainsString('[token]', implode("\n", $messages));
    }

    public function testTheNotifierJoinsTheKeyboardOnlyWhenGiven(): void
    {
        $payloads = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$payloads): MockResponse {
            $payloads[] = json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR);

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
        $notifier = new TelegramNotifier($client, new NullLogger(), self::TOKEN, '1', 1, 0);

        $notifier->send('sans boutons');
        $notifier->send('avec boutons', false, true, ['inline_keyboard' => [[['text' => 'A', 'callback_data' => 't1']]]]);

        self::assertArrayNotHasKey('reply_markup', $payloads[0]);
        self::assertSame('t1', $payloads[1]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function testTheOffsetSurvivesARestartAndIgnoresGarbage(): void
    {
        $file = sys_get_temp_dir() . '/jobscan-offset-' . bin2hex(random_bytes(4)) . '/telegram.offset';
        $store = new OffsetStore($file);

        self::assertSame(0, $store->read());
        $store->write(1234);
        self::assertSame(1234, new OffsetStore($file)->read());

        file_put_contents($file, 'abc');
        self::assertSame(0, $store->read());

        unlink($file);
        rmdir(dirname($file));
    }
}
