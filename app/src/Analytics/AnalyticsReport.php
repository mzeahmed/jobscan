<?php

declare(strict_types=1);

namespace App\Analytics;

/** Rapport complet : parcours des candidatures, répartitions et rendement des sources. */
final readonly class AnalyticsReport
{
    /**
     * @param array<string, list<BreakdownRow>> $breakdowns Par dimension : `source`, `cv`, `profile`, `channel`
     * @param list<SourcingRow> $sourcing
     */
    public function __construct(
        public ?\DateTimeImmutable $since,
        public \DateTimeImmutable $generatedAt,
        public Funnel $funnel,
        public array $breakdowns,
        public array $sourcing,
    ) {
    }
}
