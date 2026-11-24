<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use PHPUnit\Framework\TestCase;
use App\Notification\NotificationMode;
use App\AI\Extraction\ExtractionSource;
use App\Notification\NotificationLevel;
use App\Notification\NotificationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;

final class NotificationPolicyTest extends TestCase
{
    /** @return iterable<string, array{int, ?NotificationLevel}> */
    public static function levels(): iterable
    {
        yield 'très haute priorité' => [100, NotificationLevel::Priority];
        yield 'seuil prioritaire inclus' => [80, NotificationLevel::Priority];
        yield 'juste sous le seuil prioritaire' => [79, NotificationLevel::Interesting];
        yield 'seuil intéressante inclus' => [60, NotificationLevel::Interesting];
        yield 'juste sous le seuil intéressante' => [59, NotificationLevel::Secondary];
        yield 'seuil secondaire inclus' => [40, NotificationLevel::Secondary];
        yield 'juste sous le seuil secondaire' => [39, null];
        yield 'priorité nulle' => [0, null];
    }

    #[DataProvider('levels')]
    public function testClassifiesByPriorityThresholds(int $priority, ?NotificationLevel $expected): void
    {
        self::assertSame($expected, new NotificationPolicy()->levelFor($priority));
    }

    public function testAnHeuristicExtractionCanNeverBeAPriorityOpportunity(): void
    {
        $policy = new NotificationPolicy();

        self::assertSame(NotificationLevel::Interesting, $policy->levelFor(95, ExtractionSource::Heuristic));
        self::assertSame(NotificationLevel::Priority, $policy->levelFor(95, ExtractionSource::Llm));
        self::assertSame(NotificationLevel::Interesting, $policy->levelFor(65, ExtractionSource::Heuristic));
        self::assertNull($policy->levelFor(10, ExtractionSource::Heuristic));
    }

    public function testThePriorityOpportunityIsAlwaysImmediateAndTheOnlyLoudOne(): void
    {
        $policy = new NotificationPolicy(interestingMode: 'off', secondaryMode: 'off');

        self::assertSame(NotificationMode::Immediate, $policy->mode(NotificationLevel::Priority));
        self::assertFalse($policy->isSilent(NotificationLevel::Priority));
        self::assertTrue($policy->isSilent(NotificationLevel::Interesting));
        self::assertTrue($policy->isSilent(NotificationLevel::Secondary));
    }

    /** @return iterable<string, array{string, NotificationMode}> */
    public static function interestingModes(): iterable
    {
        yield 'immédiate' => ['immediate', NotificationMode::Immediate];
        yield 'récapitulatif' => ['digest', NotificationMode::Digest];
        yield 'aucune' => ['off', NotificationMode::Off];
    }

    #[DataProvider('interestingModes')]
    public function testTheInterestingModeIsConfigurable(string $configured, NotificationMode $expected): void
    {
        self::assertSame($expected, new NotificationPolicy(interestingMode: $configured)->mode(NotificationLevel::Interesting));
    }

    public function testTheSecondaryModeIsADigestByDefault(): void
    {
        self::assertSame(NotificationMode::Digest, new NotificationPolicy()->mode(NotificationLevel::Secondary));
        self::assertSame(NotificationMode::Off, new NotificationPolicy(secondaryMode: 'off')->mode(NotificationLevel::Secondary));
    }

    public function testTheMinimumPriorityIgnoresDisabledLevels(): void
    {
        self::assertSame(40, new NotificationPolicy()->minimumPriority());
        self::assertSame(60, new NotificationPolicy(secondaryMode: 'off')->minimumPriority());
        self::assertSame(80, new NotificationPolicy(interestingMode: 'off', secondaryMode: 'off')->minimumPriority());
    }

    /** @return iterable<string, array<string, mixed>> */
    public static function invalid(): iterable
    {
        yield 'seuil négatif' => [['secondaryThreshold' => -1]];
        yield 'seuil supérieur à 100' => [['priorityThreshold' => 101]];
        yield 'seuils croissants' => [['priorityThreshold' => 50, 'interestingThreshold' => 60]];
        yield 'seuils croissants (2)' => [['interestingThreshold' => 30, 'secondaryThreshold' => 40]];
        yield 'mode inconnu' => [['interestingMode' => 'sms']];
        yield 'secondaire immédiat' => [['secondaryMode' => 'immediate']];
        yield 'secondaire inconnu' => [['secondaryMode' => 'sms']];
        yield 'récapitulatif vide' => [['digestMaxItems' => 0]];
        yield 'ancienneté nulle' => [['maxAgeDays' => 0]];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalid')]
    public function testRejectsInvalidConfiguration(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NotificationPolicy(...$arguments);
    }

    public function testEqualThresholdsAreAllowed(): void
    {
        self::assertSame(NotificationLevel::Priority, new NotificationPolicy(70, 70, 70)->levelFor(70));
    }

    public function testTheOldestNotifiableDateFollowsTheConfiguredAge(): void
    {
        $now = new \DateTimeImmutable('2026-12-01 12:00:00');

        self::assertSame('2026-11-28', new NotificationPolicy()->oldestNotifiable($now)->format('Y-m-d'));
        self::assertSame('2026-11-24', new NotificationPolicy(maxAgeDays: 7)->oldestNotifiable($now)->format('Y-m-d'));
    }

    public function testExposesTheDigestSize(): void
    {
        self::assertSame(15, new NotificationPolicy()->digestMaxItems());
        self::assertSame(5, new NotificationPolicy(digestMaxItems: 5)->digestMaxItems());
    }
}
