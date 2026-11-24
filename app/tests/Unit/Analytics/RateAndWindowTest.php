<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Analytics\Rate;
use App\Analytics\Window;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RateAndWindowTest extends TestCase
{
    public function testNoPercentageBelowTheMinimumSample(): void
    {
        self::assertNull(Rate::of(1, 4));
        self::assertNull(Rate::of(0, 0));
        self::assertSame('1/2', Rate::format(1, 2));
        self::assertSame(0.4, Rate::of(2, 5));
        self::assertSame('2/5 (40 %)', Rate::format(2, 5));
        self::assertSame('0/5 (0 %)', Rate::format(0, 5));
    }

    /**
     * @return iterable<string, array{string, string|null|false}>
     */
    public static function windows(): iterable
    {
        yield '30 jours' => ['30d', '2026-11-01'];
        yield '12 semaines' => ['12w', '2026-09-08'];
        yield 'tout' => ['all', null];
        yield 'majuscules et espaces' => [' 7D ', '2026-11-24'];
        yield 'zéro' => ['0d', false];
        yield 'unité inconnue' => ['3m', false];
        yield 'négatif' => ['-5d', false];
        yield 'texte' => ['abc', false];
        yield 'vide' => ['', false];
    }

    #[DataProvider('windows')]
    public function testWindows(string $expression, string | null | false $expected): void
    {
        $since = Window::since($expression, new \DateTimeImmutable('2026-12-01 12:00:00'));

        self::assertSame($expected, $since instanceof \DateTimeImmutable ? $since->format('Y-m-d') : $since);
    }
}
