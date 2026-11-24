<?php

declare(strict_types=1);

namespace App\Tests\Unit\Matching;

use App\Matching\MatchResult;
use App\Matching\MatchVerdict;
use PHPUnit\Framework\TestCase;
use App\Matching\PriorityCalculator;
use App\AI\Extraction\ExtractionSource;

final class PriorityCalculatorTest extends TestCase
{
    public function testTheDefaultWeightsAreSeventyFiveAndTwentyFive(): void
    {
        self::assertSame(['match' => 75, 'freshness' => 25], new PriorityCalculator()->weights());
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function eligible(): iterable
    {
        yield 'compatible et très frais' => [80, 100, 85];
        yield 'compatible, date inconnue' => [80, 25, 66];
        yield 'compatible et ancien' => [80, 5, 61];
        yield 'compatibilité parfaite, offre ancienne' => [100, 5, 76];
        yield 'compatibilité moyenne, offre fraîche' => [60, 100, 70];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('eligible')]
    public function testEligibleJobsGetTheWeightedAverage(int $match, int $freshness, int $expected): void
    {
        self::assertSame($expected, new PriorityCalculator()->priority($this->matchResult(MatchVerdict::Eligible, $match), $freshness));
    }

    public function testAnEliminatedJobHasNoPriorityHoweverFreshAndCompatible(): void
    {
        self::assertSame(0, new PriorityCalculator()->priority($this->matchResult(MatchVerdict::Eliminated, 100), 100));
    }

    public function testFreshnessAloneNeverMakesAnIncompatibleJobAPriority(): void
    {
        $calculator = new PriorityCalculator();

        // Toute fraîche (100) mais sous le seuil : la priorité ne dépasse pas la compatibilité (20), au lieu de 40.
        $below = $calculator->priority($this->matchResult(MatchVerdict::BelowThreshold, 20), 100);
        $insufficient = $calculator->priority($this->matchResult(MatchVerdict::InsufficientData, 30), 100);

        self::assertSame(20, $below);
        self::assertSame(30, $insufficient);
        self::assertGreaterThan($below, $calculator->priority($this->matchResult(MatchVerdict::Eligible, 60), 5));
    }

    public function testAStaleCompatibleJobBeatsAFreshIncompatibleOne(): void
    {
        $calculator = new PriorityCalculator();

        $oldButGood = $calculator->priority($this->matchResult(MatchVerdict::Eligible, 70), 5);
        $freshButBad = $calculator->priority($this->matchResult(MatchVerdict::BelowThreshold, 40), 100);

        self::assertGreaterThan($freshButBad, $oldButGood);
    }

    public function testTheWeightsAreConfigurable(): void
    {
        $freshnessOnly = new PriorityCalculator(0, 100);
        $matchOnly = new PriorityCalculator(100, 0);

        self::assertSame(100, $freshnessOnly->priority($this->matchResult(MatchVerdict::Eligible, 70), 100));
        self::assertSame(70, $matchOnly->priority($this->matchResult(MatchVerdict::Eligible, 70), 100));
        self::assertSame(20, $freshnessOnly->priority($this->matchResult(MatchVerdict::BelowThreshold, 20), 100), 'Même avec toute la pondération sur la fraîcheur, le garde-fou s\'applique.');
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidWeights(): iterable
    {
        yield 'tous nuls' => [0, 0];
        yield 'négatif' => [-1, 50];
        yield 'négatif aussi' => [50, -1];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidWeights')]
    public function testRejectsInvalidWeights(int $match, int $freshness): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PriorityCalculator($match, $freshness);
    }

    private function matchResult(MatchVerdict $verdict, int $matchScore): MatchResult
    {
        return new MatchResult('p', $verdict, $matchScore, 100, [], [], [], [], [], [], [], null, ExtractionSource::Llm);
    }
}
