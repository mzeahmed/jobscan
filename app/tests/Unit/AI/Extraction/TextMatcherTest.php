<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Extraction;

use PHPUnit\Framework\TestCase;
use App\AI\Extraction\TextMatcher;
use PHPUnit\Framework\Attributes\DataProvider;

final class TextMatcherTest extends TestCase
{
    public function testMatchingIgnoresCaseAccentsAndPunctuation(): void
    {
        $matcher = new TextMatcher('Ingénieur DÉVELOPPEMENT — GitLab CI/CD, Node.js & TypeScript');

        self::assertTrue($matcher->contains('ingenieur developpement'));
        self::assertTrue($matcher->contains('gitlab ci cd'));
        self::assertTrue($matcher->contains('NodeJS'));
        self::assertTrue($matcher->contains('typescript'));
        self::assertFalse($matcher->contains('javascript'));
        self::assertFalse($matcher->contains(''));
        self::assertFalse($matcher->contains('---'));
    }

    public function testShortTermsMatchWholeWordsOnly(): void
    {
        $matcher = new TextMatcher('Google Cloud, Rust, C++ et C#. Aussi du Go.');

        self::assertTrue($matcher->contains('Go'));
        self::assertTrue($matcher->contains('C++'));
        self::assertTrue($matcher->contains('C#'));
        self::assertFalse($matcher->contains('R'));
        self::assertFalse($matcher->contains('C'));

        self::assertFalse(new TextMatcher('Google Cloud')->contains('Go'));
        self::assertFalse(new TextMatcher('Expert Rust')->contains('R'));
    }

    public function testContainsAny(): void
    {
        $matcher = new TextMatcher('Full remote');

        self::assertTrue($matcher->containsAny(['hybride', 'remote']));
        self::assertFalse($matcher->containsAny(['hybride', 'présentiel']));
        self::assertFalse($matcher->containsAny([]));
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function amounts(): iterable
    {
        yield 'entier brut' => ['Salaire 45000 euros', 45000, true];
        yield 'espace insécable' => ["Salaire 45\u{00A0}000 euros", 45000, true];
        yield 'espace' => ['Salaire 45 000 euros', 45000, true];
        yield 'point' => ['Salaire 45.000 euros', 45000, true];
        yield 'virgule' => ['Salaire 45,000 euros', 45000, true];
        yield 'k' => ['Salaire 45k€', 45000, true];
        yield 'k séparé' => ['Salaire 45 k€', 45000, true];
        yield 'TJM' => ['TJM 500€/j', 500, true];
        yield 'absent' => ['Salaire attractif', 45000, false];
        yield 'préfixe d\'un autre nombre' => ['Salaire 145000 euros', 45000, false];
        yield 'suffixe d\'un autre nombre' => ['Salaire 450000 euros', 45000, false];
        yield 'k ne vaut que pour les milliers ronds' => ['Salaire 45k', 45500, false];
        yield 'décimale' => ['Taux 500.50 euros', 500, false];
    }

    #[DataProvider('amounts')]
    public function testContainsAmount(string $text, int $amount, bool $expected): void
    {
        self::assertSame($expected, new TextMatcher($text)->containsAmount($amount));
    }
}
