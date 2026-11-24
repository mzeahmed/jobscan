<?php

declare(strict_types=1);

namespace App\Tests\Unit\Email;

use Psr\Log\NullLogger;
use App\Email\EmailMessage;
use App\Email\ImapEmailClient;
use PHPUnit\Framework\TestCase;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;
use DirectoryTree\ImapEngine\Testing\FakeMessage;

/**
 * Le client est testé avec les doubles fournis par la bibliothèque : aucun serveur IMAP réel.
 * La conversation IMAP réelle (connexion, authentification, SEARCH SINCE, FETCH) reste à valider
 * sur une vraie boîte — voir docs/EMAIL.md.
 */
final class ImapEmailClientTest extends TestCase
{
    public function testReturnsDownloadedMessagesWithTheirBodies(): void
    {
        $client = $this->client([$this->message(11, 'a@linkedin.com', '<html><body><a href="https://www.linkedin.com/jobs/view/4012345678">PHP</a></body></html>')]);

        $messages = $client->fetchSince(new \DateTimeImmutable('-7 days'), 10, static fn (): bool => false);

        self::assertCount(1, $messages);
        self::assertSame(11, $messages[0]->uid);
        self::assertSame('INBOX', $messages[0]->folder);
        self::assertSame('a@linkedin.com', $messages[0]->fromAddress);
        self::assertSame('Alerte 11', $messages[0]->subject);
        self::assertSame('msg-11@test', $messages[0]->messageId, 'La bibliothèque retire les chevrons ; la clé de déduplication les ignore.');
        self::assertStringContainsString('linkedin.com/jobs/view/4012345678', (string) $messages[0]->html);
        self::assertNotNull($messages[0]->receivedAt);
    }

    public function testSkipsMessagesAlreadyProcessedBeforeDownloadingThem(): void
    {
        $client = $this->client([$this->message(1, 'a@linkedin.com', '<p>1</p>'), $this->message(2, 'a@linkedin.com', '<p>2</p>')]);
        $processedKey = EmailMessage::keyFor('<msg-1@test>', 'INBOX', null, 1);

        $messages = $client->fetchSince(new \DateTimeImmutable('-7 days'), 10, static fn (string $key): bool => $key === $processedKey);

        self::assertSame([2], array_map(static fn (EmailMessage $m): int => $m->uid, $messages));
    }

    public function testStopsAtTheLimitCountingOnlyUnprocessedMessages(): void
    {
        $client = $this->client(array_map(fn (int $uid): FakeMessage => $this->message($uid, 'a@linkedin.com', '<p>x</p>'), [1, 2, 3, 4]));
        $skipped = EmailMessage::keyFor('<msg-1@test>', 'INBOX', null, 1);

        $messages = $client->fetchSince(new \DateTimeImmutable('-7 days'), 2, static fn (string $key): bool => $key === $skipped);

        self::assertSame([2, 3], array_map(static fn (EmailMessage $m): int => $m->uid, $messages));
    }

    public function testRefusesToRunWhenUnconfigured(): void
    {
        $client = new ImapEmailClient(new NullLogger());

        self::assertFalse($client->isHealthy());
        $this->expectException(\RuntimeException::class);
        $client->fetchSince(new \DateTimeImmutable(), 10, static fn (): bool => false);
    }

    public function testHealthChecksTheFolderAndNeverRaises(): void
    {
        self::assertTrue($this->client([])->isHealthy());
        self::assertFalse($this->client([], folder: 'Alertes')->isHealthy(), 'Dossier inexistant.');

        $broken = new ImapEmailClient(
            new NullLogger(),
            'imap.test',
            username: 'u',
            password: 'p',
            mailboxFactory: static fn (): MailboxInterface => throw new \RuntimeException('Connexion refusée'),
        );
        self::assertFalse($broken->isHealthy());
    }

    public function testErrorsNeverContainTheCredentials(): void
    {
        $logger = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string | \Stringable $message, array $context = []): void
            {
                $this->lines[] = $message . json_encode($context);
            }
        };
        $client = new ImapEmailClient(
            $logger,
            'imap.test',
            username: 'jobs@example.test',
            password: 'mot-de-passe-secret',
            mailboxFactory: static fn (): MailboxInterface => throw new \RuntimeException('Authentification refusée'),
        );

        $client->isHealthy();

        self::assertNotEmpty($logger->lines);
        self::assertStringNotContainsString('mot-de-passe-secret', implode(' ', $logger->lines));
    }

    /** @param list<FakeMessage> $messages */
    private function client(array $messages, string $folder = 'INBOX'): ImapEmailClient
    {
        return new ImapEmailClient(
            new NullLogger(),
            'imap.test',
            username: 'jobs@example.test',
            password: 'secret',
            folder: $folder === 'Alertes' ? 'Alertes' : 'INBOX',
            mailboxFactory: static fn (): MailboxInterface => new FakeMailbox([], [new FakeFolder('INBOX', [], $messages)]),
        );
    }

    private function message(int $uid, string $from, string $html): FakeMessage
    {
        $raw = "From: Alerte <$from>\r\nTo: jobs@example.test\r\nSubject: Alerte $uid\r\n"
            . "Message-ID: <msg-$uid@test>\r\nDate: Mon, 23 Nov 2026 08:00:00 +0000\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html";

        return new FakeMessage($uid, [], $raw);
    }
}
