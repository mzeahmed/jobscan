<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring;

use PHPUnit\Framework\TestCase;
use App\Monitoring\FetchDiagnostics;
use App\Monitoring\ProviderRunStatus;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProviderRunStatusTest extends TestCase
{
    /** @return iterable<string, array{bool, int, int, int, ProviderRunStatus}> */
    public static function outcomes(): iterable
    {
        yield 'succès avec nouvelles offres' => [false, 10, 3, 0, ProviderRunStatus::Succeeded];
        yield 'succès sans nouvelle offre' => [false, 10, 0, 0, ProviderRunStatus::NoNewJobs];
        yield 'collecte vide sans erreur' => [false, 0, 0, 0, ProviderRunStatus::NoNewJobs];
        yield 'succès partiel' => [false, 5, 1, 2, ProviderRunStatus::Partial];
        yield 'échec' => [false, 0, 0, 1, ProviderRunStatus::Failed];
        yield 'désactivée' => [true, 0, 0, 0, ProviderRunStatus::Disabled];
    }

    #[DataProvider('outcomes')]
    public function testClassifiesRuns(bool $disabled, int $fetched, int $new, int $errors, ProviderRunStatus $expected): void
    {
        self::assertSame($expected, ProviderRunStatus::classify($disabled, $fetched, $new, $errors));
    }

    public function testOnlyCleanRunsCountAsSuccessful(): void
    {
        self::assertTrue(ProviderRunStatus::Succeeded->isSuccessful());
        self::assertTrue(ProviderRunStatus::NoNewJobs->isSuccessful());
        self::assertFalse(ProviderRunStatus::Partial->isSuccessful());
        self::assertFalse(ProviderRunStatus::Failed->isSuccessful());
        self::assertFalse(ProviderRunStatus::Disabled->isSuccessful());
    }

    public function testDiagnosticsAreDrainedPerProvider(): void
    {
        $diagnostics = new FetchDiagnostics();
        $diagnostics->record('rss', 'timeout');
        $diagnostics->record('searxng', 'HTTP 500');
        $diagnostics->markDisabled('remoteok');

        self::assertSame(['errors' => ['timeout'], 'disabled' => false], $diagnostics->drain('rss'));
        self::assertSame(['errors' => [], 'disabled' => false], $diagnostics->drain('rss'));
        self::assertSame(['errors' => ['HTTP 500'], 'disabled' => false], $diagnostics->drain('searxng'));
        self::assertSame(['errors' => [], 'disabled' => true], $diagnostics->drain('remoteok'));
    }
}
