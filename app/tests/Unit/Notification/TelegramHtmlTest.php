<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use PHPUnit\Framework\TestCase;
use App\Notification\TelegramHtml;

final class TelegramHtmlTest extends TestCase
{
    public function testEscapesWithEntitiesThatTelegramUnderstands(): void
    {
        $escaped = TelegramHtml::escape('Ingénieur d\'affaires "PHP" & <Go>');

        self::assertSame('Ingénieur d&#039;affaires &quot;PHP&quot; &amp; &lt;Go&gt;', $escaped);
        self::assertStringNotContainsString('&apos;', $escaped, 'Telegram ne connaît pas `&apos;`.');
    }

    public function testInvalidUtf8IsSubstitutedNotDropped(): void
    {
        self::assertNotSame('', TelegramHtml::escape("Offre \xB1 PHP"));
    }

    public function testBuildsALinkForHttpUrlsOnly(): void
    {
        self::assertSame('<a href="https://example.test/a?b=1&amp;c=2">Voir</a>', TelegramHtml::link('https://example.test/a?b=1&c=2', 'Voir'));
        self::assertSame('<a href="http://example.test">Voir</a>', TelegramHtml::link('http://example.test', 'Voir'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'données' => ['data:text/html,<script>alert(1)</script>'];
        yield 'ftp' => ['ftp://example.test/a'];
        yield 'relative' => ['/offres/12'];
        yield 'vide' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeUrls')]
    public function testNeverBuildsALinkFromAnUnsafeUrl(string $url): void
    {
        $link = TelegramHtml::link($url, 'Voir');

        self::assertStringNotContainsString('<a ', $link);
        self::assertStringNotContainsString('<script', $link);
        self::assertSame('Voir', $link);
    }

    public function testAnInjectedQuoteCannotBreakOutOfTheHrefAttribute(): void
    {
        $link = TelegramHtml::link('https://example.test/"><script>alert(1)</script>', 'Voir');

        self::assertSame(1, substr_count($link, '<a '));
        self::assertStringNotContainsString('<script', $link);
        self::assertStringNotContainsString('"><', $link);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $link);
    }

    public function testTheLabelIsEscaped(): void
    {
        self::assertSame('<a href="https://example.test">&lt;b&gt;x&lt;/b&gt;</a>', TelegramHtml::link('https://example.test', '<b>x</b>'));
    }
}
