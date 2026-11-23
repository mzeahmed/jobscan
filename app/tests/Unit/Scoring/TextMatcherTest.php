<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scoring;

use App\Scoring\TextMatcher;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TextMatcherTest extends TestCase
{
    #[DataProvider('cases')]
    public function testContains(string $text, string $keyword, bool $expected): void
    {
        $this->assertSame($expected, TextMatcher::contains($text, $keyword));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'mot entier' => ['Développeur PHP senior', 'php', true];
        yield 'sous-chaîne rejetée (api/capital)' => ['Capital risque', 'api', false];
        yield 'sous-chaîne rejetée (mission/commission)' => ['Commission annuelle', 'mission', false];
        yield 'sous-chaîne rejetée (stage/backstage)' => ['Backstage crew', 'stage', false];
        yield 'sous-chaîne rejetée (mid/middleware)' => ['Middleware Java', 'mid', false];
        yield 'phpstorm ne matche pas php' => ['Utilise PhpStorm', 'php', false];
        yield 'ponctuation adjacente' => ['Stack: PHP/Symfony, API.', 'api', true];
        yield 'insensible aux accents' => ['Télétravail complet', 'teletravail', true];
        yield 'mot-clé accentué' => ['teletravail complet', 'télétravail', true];
        yield 'mot composé avec tiret' => ['Poste full-stack', 'full-stack', true];
        yield 'mot-clé vide' => ['texte', '', false];
    }
}
