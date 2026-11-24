<?php

declare(strict_types=1);

namespace App\Health;

use Doctrine\DBAL\Connection;
use App\Profile\ProfileRegistry;
use App\Security\UiUserProvider;
use App\Monitoring\ProviderRunStatus;
use App\Profile\InvalidProfileException;

/**
 * Contrôles de santé de l'installation : base et migrations, sources d'offres, file d'analyses, notifications, rappels,
 * disque, profils. Chaque contrôle est indépendant : l'échec de l'un n'empêche pas les autres, et un contrôle qui plante est
 * signalé « critique » plutôt qu'ignoré.
 *
 * Aucun appel réseau, aucun appel au LLM : le contrôle est rapide, gratuit et sans effet de bord.
 */
final readonly class HealthService
{
    /** @param null|\Closure(string): array{float, float}|null $diskSpace Remplace la lecture du disque (octets libres, octets totaux), pour les tests */
    public function __construct(
        private Connection $connection,
        private ProfileRegistry $profiles,
        private UiUserProvider $uiUser,
        private HealthThresholds $thresholds,
        private string $projectDir,
        private ?\Closure $diskSpace = null,
    ) {
    }

    public function run(\DateTimeImmutable $now): HealthReport
    {
        $checks = [];
        $plan = [
            'base de données' => $this->database(...),
            'migrations' => $this->migrations(...),
            'sources' => $this->providers(...),
            'analyses' => $this->analysis(...),
            'notifications' => $this->notifications(...),
            'rappels' => $this->reminders(...),
            'disque' => $this->disk(...),
            'profils' => $this->profile(...),
            'interface web' => $this->interface(...),
        ];

        foreach ($plan as $name => $check) {
            try {
                foreach ($check($now) as $result) {
                    $checks[] = $result;
                }
            } catch (\Throwable $e) {
                $checks[] = HealthCheck::critical($name, self::describeFailure($e));
            }
        }

        return new HealthReport($now, $checks);
    }

    /** Une table absente (migrations en attente) est dite clairement ; toute autre erreur est résumée, sans SQL brut. */
    private static function describeFailure(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (preg_match('/no such table|doesn\'t exist|Base table or view not found|relation .* does not exist/i', $message) === 1) {
            return 'table absente : migrations en attente, lancez `make migrate`';
        }

        return 'contrôle impossible : ' . mb_substr((string) preg_replace('/\s+/', ' ', strtok($message, "\n") ?: $message), 0, 160);
    }

    /**
     * @return list<HealthCheck>
     * @throws \Doctrine\DBAL\Exception
     */
    private function database(\DateTimeImmutable $now): array
    {
        $this->connection->fetchOne('SELECT 1');

        return [HealthCheck::ok('base de données', 'accessible')];
    }

    /**
     * @return list<HealthCheck>
     */
    private function migrations(\DateTimeImmutable $now): array
    {
        $available = [];
        foreach (glob($this->projectDir . '/migrations/Version*.php') ?: [] as $file) {
            $available[] = 'DoctrineMigrations\\' . basename($file, '.php');
        }

        try {
            /** @var list<string> $executed */
            $executed = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');
        } catch (\Throwable) {
            return [HealthCheck::critical('migrations', 'table des migrations absente : lancez `make migrate`')];
        }

        $pending = array_diff($available, $executed);
        if ($pending !== []) {
            return [HealthCheck::critical('migrations', sprintf('%d migration(s) non appliquée(s) : lancez `make migrate`', count($pending)))];
        }

        return [HealthCheck::ok('migrations', sprintf('%d appliquée(s), à jour', count($available)))];
    }

    /**
     * @return list<HealthCheck>
     * @throws \Doctrine\DBAL\Exception
     */
    private function providers(\DateTimeImmutable $now): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn('SELECT DISTINCT provider FROM provider_run ORDER BY provider');
        if ($names === []) {
            return [HealthCheck::warning('sources', 'aucune collecte enregistrée : lancez `app:jobs:run`')];
        }

        $checks = [];
        foreach ($names as $name) {
            $runs = $this->connection->fetchAllAssociative(
                'SELECT status, started_at, last_error FROM provider_run WHERE provider = :provider ORDER BY started_at DESC, id DESC LIMIT :limit',
                ['provider' => $name, 'limit' => max(1, $this->thresholds->providerFailureStreak)],
            );
            $check = $this->providerCheck('source ' . $name, $runs, $now);
            if ($check !== null) {
                $checks[] = $check;
            }
        }

        return $checks === [] ? [HealthCheck::ok('sources', 'aucune source active')] : $checks;
    }

    /**
     * @param list<array<string, mixed>> $runs Du plus récent au plus ancien
     */
    private function providerCheck(string $name, array $runs, \DateTimeImmutable $now): ?HealthCheck
    {
        $last = $runs[0] ?? null;
        $status = $last === null ? null : ProviderRunStatus::tryFrom((string) $last['status']);
        if ($last === null || $status === null || $status === ProviderRunStatus::Disabled) {
            return null;
        }

        $streak = 0;
        foreach ($runs as $run) {
            if ($run['status'] !== ProviderRunStatus::Failed->value) {
                break;
            }
            ++$streak;
        }

        $hours = (int) floor(($now->getTimestamp() - strtotime((string) $last['started_at'])) / 3600);
        $detail = is_string($last['last_error'] ?? null) && $last['last_error'] !== '' ? ' — ' . mb_substr($last['last_error'], 0, 120) : '';

        if ($streak >= $this->thresholds->providerFailureStreak) {
            return HealthCheck::critical($name, sprintf('%d échecs consécutifs%s', $streak, $detail));
        }
        if ($hours >= $this->thresholds->providerStaleHours * 3) {
            return HealthCheck::critical($name, sprintf('aucune exécution depuis %d h', $hours));
        }
        if ($hours >= $this->thresholds->providerStaleHours) {
            return HealthCheck::warning($name, sprintf('aucune exécution depuis %d h', $hours));
        }
        if ($status === ProviderRunStatus::Failed) {
            return HealthCheck::warning($name, 'dernière collecte en échec' . $detail);
        }
        if ($status === ProviderRunStatus::Partial) {
            return HealthCheck::warning($name, 'dernière collecte partielle' . $detail);
        }

        return HealthCheck::ok($name, sprintf('%s il y a %d h', $status === ProviderRunStatus::Succeeded ? 'nouvelles offres' : 'rien de nouveau', $hours));
    }

    /**
     *
     * @return list<HealthCheck>
     * @throws \DateMalformedStringException
     * @throws \Doctrine\DBAL\Exception
     */
    private function analysis(\DateTimeImmutable $now): array
    {
        $pending = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM job WHERE analysis_status = 'pending' AND probable_duplicate_of_id IS NULL");
        $oldest = $this->connection->fetchOne("SELECT MIN(first_seen_at) FROM job WHERE analysis_status = 'pending' AND probable_duplicate_of_id IS NULL");
        $oldestHours = is_string($oldest) ? (int) floor(($now->getTimestamp() - strtotime($oldest)) / 3600) : 0;
        $failed = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM job WHERE analysis_status = 'failed'");

        $checks = [];
        if ($pending >= $this->thresholds->analysisPendingWarning || $oldestHours >= $this->thresholds->analysisOldestHours) {
            $checks[] = HealthCheck::warning('analyses', sprintf('%d en attente, la plus ancienne depuis %d h : lancez `app:jobs:analyze`', $pending, $oldestHours));
        } else {
            $checks[] = HealthCheck::ok('analyses', sprintf('%d en attente%s', $pending, $failed > 0 ? sprintf(', %d en échec à retenter', $failed) : ''));
        }

        $since = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $row = $this->connection->fetchAssociative("SELECT COUNT(*) AS total, SUM(analysis_status = 'fallback') AS fallback FROM job WHERE analyzed_at >= :since AND analysis_status IN ('done', 'fallback')", ['since' => $since]);
        $total = (int) ($row['total'] ?? 0);
        $fallback = (int) ($row['fallback'] ?? 0);
        if ($total >= 10 && $fallback * 100 >= $total * $this->thresholds->fallbackSharePercent) {
            $checks[] = HealthCheck::warning('LLM', sprintf('%d %% des analyses des 7 derniers jours sont des replis heuristiques (%d/%d) : le moteur LLM est-il disponible ?', (int) round($fallback * 100 / $total), $fallback, $total));
        }

        return $checks;
    }

    /**
     *
     * @return list<HealthCheck>
     * @throws \DateMalformedStringException
     * @throws \Doctrine\DBAL\Exception
     */
    private function notifications(\DateTimeImmutable $now): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(DISTINCT j.id) AS missed, MIN(j.first_seen_at) AS oldest
                FROM job_match m
                JOIN job j ON j.id = m.job_id
                WHERE m.verdict = 'eligible' AND m.priority_score >= :threshold
                  AND j.notified_at IS NULL AND j.user_state IS NULL AND j.probable_duplicate_of_id IS NULL
                  AND j.first_seen_at >= :since AND j.first_seen_at <= :lagged
                  AND NOT EXISTS (SELECT 1 FROM notification_log n WHERE n.job_id = j.id)
                  AND NOT EXISTS (SELECT 1 FROM job_application a WHERE a.job_id = j.id)
                SQL,
            [
                'threshold' => $this->thresholds->priorityThreshold,
                'since' => $now->modify(sprintf('-%d days', $this->thresholds->maxAgeDays))->format('Y-m-d H:i:s'),
                'lagged' => $now->modify(sprintf('-%d hours', $this->thresholds->notificationLagHours))->format('Y-m-d H:i:s'),
            ],
        );

        $missed = (int) ($row['missed'] ?? 0);

        return $missed > 0
            ? [HealthCheck::warning('notifications', sprintf('%d offre(s) prioritaire(s) non notifiée(s) : Telegram est-il joignable ? Rattrapage : `app:notifications:digest`', $missed))]
            : [HealthCheck::ok('notifications', 'aucune notification prioritaire manquée')];
    }

    /**
     * @return list<HealthCheck>
     * @throws \Doctrine\DBAL\Exception|\DateMalformedStringException
     */
    private function reminders(\DateTimeImmutable $now): array
    {
        $missed = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM job_application WHERE follow_up_at IS NOT NULL AND follow_up_at <= :lagged AND follow_up_reminded_at IS NULL AND status NOT IN ('draft', 'accepted', 'rejected', 'withdrawn')",
            ['lagged' => $now->modify(sprintf('-%d hours', $this->thresholds->reminderLagHours))->format('Y-m-d H:i:s')],
        );

        return $missed > 0
            ? [HealthCheck::warning('rappels', sprintf('%d rappel(s) de relance non envoyé(s) : lancez `app:reminders:send` (le cron tourne-t-il ?)', $missed))]
            : [HealthCheck::ok('rappels', 'aucun rappel en retard')];
    }

    /**
     * @return list<HealthCheck>
     */
    private function disk(\DateTimeImmutable $now): array
    {
        [$free, $total] = $this->diskSpace !== null ? ($this->diskSpace)($this->projectDir . '/var') : [(float) disk_free_space($this->projectDir . '/var'), (float) disk_total_space($this->projectDir . '/var')];
        if ($total <= 0) {
            return [HealthCheck::warning('disque', 'espace disque illisible')];
        }

        $percent = $free * 100 / $total;
        $message = sprintf('%.0f %% libre (%.1f Go)', $percent, $free / 1073741824);

        return [match (true) {
            $percent < $this->thresholds->diskCriticalPercent => HealthCheck::critical('disque', $message . ' : la base risque de ne plus pouvoir écrire'),
            $percent < $this->thresholds->diskWarningPercent => HealthCheck::warning('disque', $message),
            default => HealthCheck::ok('disque', $message),
        }];
    }

    /**
     * @return list<HealthCheck>
     */
    private function profile(\DateTimeImmutable $now): array
    {
        try {
            $candidate = $this->profiles->candidate();
            $searchProfiles = $this->profiles->enabledSearchProfiles();
        } catch (InvalidProfileException $e) {
            return [HealthCheck::critical('profils', 'invalides : ' . mb_substr($e->getMessage(), 0, 160))];
        }

        if ($candidate === null) {
            return [HealthCheck::warning('profils', 'profil candidat absent : pas de matching ni de notifications fondées sur le profil (voir docs/PROFILE.md)')];
        }
        if ($searchProfiles === []) {
            return [HealthCheck::warning('profils', 'aucun profil de recherche actif : aucune offre ne sera rapprochée')];
        }

        return [HealthCheck::ok('profils', sprintf('profil candidat et %d profil(s) de recherche actif(s)', count($searchProfiles)))];
    }

    /**
     * @return list<HealthCheck>
     */
    private function interface(\DateTimeImmutable $now): array
    {
        return [HealthCheck::ok('interface web', $this->uiUser->isConfigured() ? 'protégée par mot de passe' : 'fermée (aucun mot de passe configuré)')];
    }
}
