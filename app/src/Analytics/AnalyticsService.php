<?php

declare(strict_types=1);

namespace App\Analytics;

use Doctrine\DBAL\Connection;
use App\Profile\ProfileRegistry;

/**
 * Statistiques sur **vos propres données** : candidatures, historique de statuts, offres collectées. Rien ne quitte la machine.
 *
 * Ce sont des comptages, pas des preuves : les offres visées diffèrent d'un CV, d'un canal ou d'une source à l'autre. Un
 * pourcentage n'est calculé qu'à partir de `Rate::MIN_SAMPLE` candidatures.
 */
final readonly class AnalyticsService
{
    public const array DIMENSIONS = ['source', 'cv', 'profile', 'channel'];

    public function __construct(
        private Connection $connection,
        private ProfileRegistry $profiles,
    ) {
    }

    public function report(?\DateTimeImmutable $since, \DateTimeImmutable $now): AnalyticsReport
    {
        $applications = $this->sentApplications($since);

        $breakdowns = [];
        foreach (self::DIMENSIONS as $dimension) {
            $breakdowns[$dimension] = $this->breakdown($applications, $dimension);
        }

        return new AnalyticsReport($since, $now, $this->funnel($applications), $breakdowns, $this->sourcing($since));
    }

    /**
     * Candidatures envoyées, avec ce qu'elles ont atteint d'après l'historique.
     *
     * @return list<array<string, mixed>>
     */
    private function sentApplications(?\DateTimeImmutable $since): array
    {
        $sql = <<<'SQL'
            SELECT a.id, a.status, a.applied_at, a.responded_at, a.cv_version_id, a.cv_version_label, a.search_profile_id, a.channel, j.source,
                EXISTS (SELECT 1 FROM job_application_status_change c WHERE c.application_id = a.id AND c.to_status IN ('interview', 'technical_test')) AS interview,
                EXISTS (SELECT 1 FROM job_application_status_change c WHERE c.application_id = a.id AND c.to_status IN ('offer', 'accepted')) AS offer
            FROM job_application a
            JOIN job j ON j.id = a.job_id
            WHERE a.applied_at IS NOT NULL
            SQL;
        $parameters = [];
        if ($since !== null) {
            $sql .= ' AND a.applied_at >= :since';
            $parameters['since'] = $since->format('Y-m-d H:i:s');
        }

        return $this->connection->fetchAllAssociative($sql, $parameters);
    }

    /** @param list<array<string, mixed>> $applications */
    private function funnel(array $applications): Funnel
    {
        $delays = [];
        $count = fn (callable $test): int => count(array_filter($applications, $test));

        foreach ($applications as $application) {
            if ($application['responded_at'] !== null) {
                $seconds = strtotime((string) $application['responded_at']) - strtotime((string) $application['applied_at']);
                $delays[] = max(0, $seconds) / 86400;
            }
        }
        sort($delays);

        return new Funnel(
            count($applications),
            $count(static fn (array $a): bool => $a['responded_at'] !== null),
            $count(static fn (array $a): bool => (bool) $a['interview']),
            $count(static fn (array $a): bool => (bool) $a['offer']),
            $count(static fn (array $a): bool => $a['status'] === 'accepted'),
            $count(static fn (array $a): bool => $a['status'] === 'rejected'),
            $count(static fn (array $a): bool => $a['status'] === 'no_response'),
            self::median($delays),
        );
    }

    /**
     * @param list<array<string, mixed>> $applications
     *
     * @return list<BreakdownRow> Les plus nombreuses d'abord
     */
    private function breakdown(array $applications, string $dimension): array
    {
        /** @var array<string, array{sent: int, responded: int, interviews: int, offers: int}> $groups */
        $groups = [];
        foreach ($applications as $application) {
            $label = $this->label($application, $dimension);
            $group = $groups[$label] ?? ['sent' => 0, 'responded' => 0, 'interviews' => 0, 'offers' => 0];
            ++$group['sent'];
            $group['responded'] += $application['responded_at'] !== null ? 1 : 0;
            $group['interviews'] += (bool) $application['interview'] ? 1 : 0;
            $group['offers'] += (bool) $application['offer'] ? 1 : 0;
            $groups[$label] = $group;
        }

        $rows = [];
        foreach ($groups as $label => $group) {
            $rows[] = new BreakdownRow($label, $group['sent'], $group['responded'], $group['interviews'], $group['offers']);
        }
        usort($rows, static fn (BreakdownRow $a, BreakdownRow $b): int => [$b->sent, $a->label] <=> [$a->sent, $b->label]);

        return $rows;
    }

    /** @param array<string, mixed> $application */
    private function label(array $application, string $dimension): string
    {
        $unknown = 'non renseigné';

        return match ($dimension) {
            'source' => (string) ($application['source'] ?? $unknown),
            'cv' => is_string($application['cv_version_label'] ?? null) && $application['cv_version_label'] !== ''
                ? $application['cv_version_label']
                : (is_string($application['cv_version_id'] ?? null) ? $application['cv_version_id'] : $unknown),
            'profile' => is_string($application['search_profile_id'] ?? null) ? ($this->profiles->searchProfile($application['search_profile_id'])->name ?? $application['search_profile_id']) : $unknown,
            default => is_string($application['channel'] ?? null) ? $application['channel'] : $unknown,
        };
    }

    /** @return list<SourcingRow> */
    private function sourcing(?\DateTimeImmutable $since): array
    {
        $sql = <<<'SQL'
            SELECT j.source,
                COUNT(*) AS collected,
                SUM(EXISTS (SELECT 1 FROM job_match m WHERE m.job_id = j.id AND m.verdict = 'eligible')) AS compatible,
                SUM(j.notified_at IS NOT NULL OR EXISTS (SELECT 1 FROM notification_log n WHERE n.job_id = j.id)) AS notified,
                SUM(EXISTS (SELECT 1 FROM job_application a WHERE a.job_id = j.id)) AS tracked,
                SUM(EXISTS (SELECT 1 FROM job_application a WHERE a.job_id = j.id AND a.applied_at IS NOT NULL)) AS applied
            FROM job j
            WHERE j.probable_duplicate_of_id IS NULL
            SQL;
        $parameters = [];
        if ($since !== null) {
            $sql .= ' AND j.first_seen_at >= :since';
            $parameters['since'] = $since->format('Y-m-d H:i:s');
        }
        $sql .= ' GROUP BY j.source ORDER BY collected DESC, j.source ASC';

        $rows = [];
        foreach ($this->connection->fetchAllAssociative($sql, $parameters) as $row) {
            $rows[] = new SourcingRow((string) $row['source'], (int) $row['collected'], (int) $row['compatible'], (int) $row['notified'], (int) $row['tracked'], (int) $row['applied']);
        }

        return $rows;
    }

    /** @param list<float> $sorted */
    private static function median(array $sorted): ?float
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }

        return $n % 2 === 1 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2;
    }
}
