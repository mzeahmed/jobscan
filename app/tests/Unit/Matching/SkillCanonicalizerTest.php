<?php

declare(strict_types=1);

namespace App\Tests\Unit\Matching;

use PHPUnit\Framework\TestCase;
use App\Matching\SkillCanonicalizer;
use PHPUnit\Framework\Attributes\DataProvider;

final class SkillCanonicalizerTest extends TestCase
{
    private const array ALIASES = [
        ['javascript', 'js'],
        ['nodejs', 'node', 'node.js'],
        ['postgresql', 'postgres'],
        ['gitlab ci/cd', 'gitlab ci'],
        ['csharp', 'c#'],
    ];

    /** @return iterable<string, array{string, string}> */
    public static function equivalents(): iterable
    {
        yield 'casse' => ['PHP', 'php'];
        yield 'espaces' => ['  Symfony ', 'symfony'];
        yield 'accents' => ['Développement', 'developpement'];
        yield 'ponctuation' => ['Node.js', 'node'];
        yield 'alias' => ['JS', 'JavaScript'];
        yield 'alias postgres' => ['Postgres', 'PostgreSQL'];
        yield 'alias avec séparateur' => ['GitLab CI', 'gitlab ci/cd'];
        yield 'alias à symbole' => ['C#', 'CSharp'];
        yield 'version collée' => ['PHP8', 'PHP'];
        yield 'version séparée' => ['PHP 8.3', 'php'];
        yield 'version et alias' => ['Node.js 20', 'node'];
        yield 'html5' => ['HTML5', 'html'];
    }

    #[DataProvider('equivalents')]
    public function testEquivalentNamesShareTheirKey(string $a, string $b): void
    {
        $canonicalizer = new SkillCanonicalizer(self::ALIASES);

        self::assertSame($canonicalizer->key($a), $canonicalizer->key($b));
    }

    /** @return iterable<string, array{string, string}> */
    public static function distinct(): iterable
    {
        yield 'java et javascript' => ['Java', 'JavaScript'];
        yield 'go et google' => ['Go', 'Google Cloud'];
        yield 'c et c++' => ['C', 'C++'];
        yield 'c++ et c#' => ['C++', 'C#'];
        yield 'react et reactor' => ['React', 'Reactor'];
        yield 'mysql et postgresql' => ['MySQL', 'PostgreSQL'];
    }

    #[DataProvider('distinct')]
    public function testDifferentSkillsKeepDifferentKeys(string $a, string $b): void
    {
        $canonicalizer = new SkillCanonicalizer(self::ALIASES);

        self::assertNotSame($canonicalizer->key($a), $canonicalizer->key($b));
    }

    public function testShortNamesKeepTheirDigits(): void
    {
        $canonicalizer = new SkillCanonicalizer();

        self::assertSame('es6', $canonicalizer->key('ES6'));
        self::assertSame('ec2', $canonicalizer->key('EC2'));
        self::assertSame('s3', $canonicalizer->key('S3'));
    }

    public function testWorksWithoutAnyAlias(): void
    {
        self::assertSame('postgres', new SkillCanonicalizer()->key('Postgres'));
        self::assertNotSame(new SkillCanonicalizer()->key('Postgres'), new SkillCanonicalizer()->key('PostgreSQL'));
    }
}
