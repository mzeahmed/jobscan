<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scoring;

use PHPUnit\Framework\TestCase;
use App\Scoring\FreshnessScorer;
use App\DTO\PublicationDateConfidence;
use PHPUnit\Framework\Attributes\DataProvider;

final class FreshnessScorerTest extends TestCase
{
    private const array BANDS = [
        ['max_hours' => 6, 'score' => 100],
        ['max_hours' => 24, 'score' => 90],
        ['max_hours' => 48, 'score' => 75],
        ['max_hours' => 96, 'score' => 60],
        ['max_hours' => 168, 'score' => 40],
        ['max_hours' => 336, 'score' => 20],
    ];

    /** @return iterable<string, array{string, int}> */
    public static function ageBands(): iterable
    {
        yield 'moins de 6 h' => ['-5 hours', 100];
        yield 'borne 6 h exclue' => ['-6 hours', 90];
        yield '6 à 24 h' => ['-23 hours', 90];
        yield '24 à 48 h' => ['-30 hours', 75];
        yield '48 à 96 h' => ['-3 days', 60];
        yield '4 à 7 jours' => ['-5 days', 40];
        yield '7 à 14 jours' => ['-10 days', 20];
        yield 'plus de 14 jours' => ['-30 days', 5];
    }

    #[DataProvider('ageBands')]
    public function testScoresTheAgeBands(string $age, int $expected): void
    {
        $now = new \DateTimeImmutable('2026-11-24 12:00:00');

        self::assertSame($expected, $this->scorer()->score($now->modify($age), PublicationDateConfidence::High, $now));
    }

    public function testAnUnknownDateNeverGetsTheMaximumScore(): void
    {
        $now = new \DateTimeImmutable();
        $scorer = $this->scorer();

        self::assertSame(25, $scorer->score(null, PublicationDateConfidence::Unknown, $now));
        self::assertSame(25, $scorer->score($now, PublicationDateConfidence::Unknown, $now), 'Date présente mais de fiabilité inconnue.');
    }

    public function testALowConfidenceDateIsCapped(): void
    {
        $now = new \DateTimeImmutable();

        self::assertSame(50, $this->scorer()->score($now->modify('-1 hour'), PublicationDateConfidence::Low, $now));
        self::assertSame(40, $this->scorer()->score($now->modify('-5 days'), PublicationDateConfidence::Low, $now), 'Le plafond ne relève jamais un score.');
    }

    public function testAFutureDateIsTreatedAsUnreliable(): void
    {
        $now = new \DateTimeImmutable('2026-11-24 12:00:00');

        self::assertSame(25, $this->scorer()->score($now->modify('+2 days'), PublicationDateConfidence::High, $now));
        self::assertSame(100, $this->scorer()->score($now->modify('+10 minutes'), PublicationDateConfidence::High, $now), "Tolérance sur l'horloge.");
    }

    public function testRejectsUnsortedBands(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FreshnessScorer([['max_hours' => 24, 'score' => 90], ['max_hours' => 6, 'score' => 100]]);
    }

    private function scorer(): FreshnessScorer
    {
        return new FreshnessScorer(self::BANDS, olderScore: 5, unknownScore: 25, lowConfidenceCap: 50);
    }
}
