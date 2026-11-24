<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analysis;

use PHPUnit\Framework\TestCase;
use App\Analysis\AnalysisRetryPolicy;

final class AnalysisRetryPolicyTest extends TestCase
{
    public function testBacksOffExponentiallyWithACap(): void
    {
        $policy = new AnalysisRetryPolicy(maxAttempts: 6, initialDelayMinutes: 15, multiplier: 2.0, maxDelayMinutes: 60);
        $now = new \DateTimeImmutable('2026-11-24 12:00:00');

        self::assertSame('12:15', $policy->nextAttemptAt(1, $now)?->format('H:i'));
        self::assertSame('12:30', $policy->nextAttemptAt(2, $now)?->format('H:i'));
        self::assertSame('13:00', $policy->nextAttemptAt(3, $now)?->format('H:i'));
        self::assertSame('13:00', $policy->nextAttemptAt(5, $now)?->format('H:i'), 'Délai plafonné.');
    }

    public function testStopsPlanningRetriesOnceTheAttemptsAreExhausted(): void
    {
        $policy = new AnalysisRetryPolicy(maxAttempts: 3);

        self::assertNotNull($policy->nextAttemptAt(2, new \DateTimeImmutable()));
        self::assertNull($policy->nextAttemptAt(3, new \DateTimeImmutable()));
    }
}
