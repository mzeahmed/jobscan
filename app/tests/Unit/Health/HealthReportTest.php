<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health;

use App\Health\HealthCheck;
use App\Health\HealthReport;
use App\Health\HealthStatus;
use PHPUnit\Framework\TestCase;
use App\Health\HealthStateStore;

final class HealthReportTest extends TestCase
{
    private function report(HealthCheck ...$checks): HealthReport
    {
        return new HealthReport(new \DateTimeImmutable('2026-12-01'), array_values($checks));
    }

    public function testTheWorstStatusWins(): void
    {
        self::assertSame(HealthStatus::Ok, $this->report(HealthCheck::ok('a', 'x'))->status());
        self::assertSame(HealthStatus::Ok, $this->report()->status());
        self::assertSame(HealthStatus::Warning, $this->report(HealthCheck::ok('a', 'x'), HealthCheck::warning('b', 'y'))->status());
        self::assertSame(HealthStatus::Critical, $this->report(HealthCheck::warning('b', 'y'), HealthCheck::critical('c', 'z'), HealthCheck::ok('a', 'x'))->status());
    }

    public function testProblemsExcludeOkChecks(): void
    {
        $report = $this->report(HealthCheck::ok('a', 'x'), HealthCheck::warning('b', 'y'));

        self::assertSame(['b'], array_map(static fn (HealthCheck $c): string => $c->name, $report->problems()));
    }

    public function testTheSignatureIgnoresMessageWordingButTracksStatusAndPresence(): void
    {
        $first = $this->report(HealthCheck::warning('sources', '3 échecs'), HealthCheck::ok('disque', '40 %'));
        $reworded = $this->report(HealthCheck::ok('disque', '41 %'), HealthCheck::warning('sources', '4 échecs'));
        $worse = $this->report(HealthCheck::critical('sources', '3 échecs'));
        $healthy = $this->report(HealthCheck::ok('sources', 'ok'));

        self::assertSame($first->signature(), $reworded->signature(), 'Un compteur qui varie ne relance pas d\'alerte.');
        self::assertNotSame($first->signature(), $worse->signature());
        self::assertSame('', $healthy->signature());
    }

    public function testTheStateStoreSurvivesARestart(): void
    {
        $file = sys_get_temp_dir() . '/jobscan-health-' . bin2hex(random_bytes(4)) . '/state';
        $store = new HealthStateStore($file);

        self::assertNull($store->read());
        $store->write('sources=warning');
        self::assertSame('sources=warning', new HealthStateStore($file)->read());
        $store->write('');
        self::assertSame('', $store->read());

        unlink($file);
        rmdir(dirname($file));
    }
}
