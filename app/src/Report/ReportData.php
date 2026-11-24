<?php

declare(strict_types=1);

namespace App\Report;

use App\Health\HealthCheck;

/** Ce qui s'est passé sur la période, prêt à être rendu. */
final readonly class ReportData
{
    /**
     * @param list<array{title: string, company: ?string, url: string, priority: int}> $toSort Meilleures offres compatibles encore à trier
     * @param list<HealthCheck> $problems Contrôles de santé qui ne sont pas « ok »
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public int $collected,
        public int $compatible,
        public int $notified,
        public array $toSort,
        public int $applicationsSent,
        public int $responses,
        public int $interviews,
        public int $offers,
        public int $dueFollowUps,
        public int $healthChecks,
        public array $problems,
    ) {
    }

    public function days(): int
    {
        return max(1, (int) round(($this->to->getTimestamp() - $this->from->getTimestamp()) / 86400));
    }
}
