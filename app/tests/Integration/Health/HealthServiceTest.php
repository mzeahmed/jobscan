<?php

declare(strict_types=1);

namespace App\Tests\Integration\Health;

use App\Health\HealthCheck;
use App\Health\HealthStatus;
use App\Health\HealthService;
use Doctrine\DBAL\Connection;
use App\Health\HealthThresholds;
use App\Profile\ProfileRegistry;
use App\Security\UiUserProvider;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Tests\Integration\Notification\NotificationIntegrationTestCase;

final class HealthServiceTest extends NotificationIntegrationTestCase
{
    private Connection $db;
    /** @var array{float, float} */
    private array $disk = [500.0, 1000.0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeNotificationProfile();
        $this->db = $this->entityManager->getConnection();
        $this->applyAllMigrations();
    }

    private function applyAllMigrations(int $skip = 0): void
    {
        $this->db->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');
        $this->db->executeStatement('CREATE TABLE doctrine_migration_versions (version VARCHAR(191) PRIMARY KEY, executed_at DATETIME DEFAULT NULL, execution_time INTEGER DEFAULT NULL)');
        $files = glob(self::getContainer()->getParameter('kernel.project_dir') . '/migrations/Version*.php') ?: [];
        foreach (array_slice($files, 0, count($files) - $skip) as $file) {
            $this->db->insert('doctrine_migration_versions', ['version' => 'DoctrineMigrations\\' . basename($file, '.php')]);
        }
    }

    private function service(?HealthThresholds $thresholds = null): HealthService
    {
        return new HealthService(
            $this->db,
            self::getContainer()->get(ProfileRegistry::class),
            new UiUserProvider('admin', '$2y$04$abcdefghijklmnopqrstuv'),
            $thresholds ?? new HealthThresholds(),
            self::getContainer()->getParameter('kernel.project_dir'),
            fn (): array => $this->disk,
        );
    }

    private function check(string $name, ?HealthThresholds $thresholds = null): HealthCheck
    {
        foreach ($this->service($thresholds)->run($this->now)->checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        self::fail(sprintf('Aucun contrôle « %s ».', $name));
    }

    private function recordRun(string $provider, string $status, string $hoursAgo, ?string $error = null): void
    {
        $this->db->insert('provider_run', [
            'provider' => $provider, 'started_at' => $this->now->modify('-' . $hoursAgo . ' hours')->format('Y-m-d H:i:s'), 'duration_ms' => 100, 'status' => $status,
            'fetched_count' => 5, 'new_count' => 1, 'duplicate_count' => 4, 'error_count' => $status === 'failed' ? 1 : 0, 'last_error' => $error,
        ]);
    }

    public function testAHealthyInstallationIsOk(): void
    {
        $this->recordRun('remoteok', 'succeeded', '1');

        $report = $this->service()->run($this->now);

        self::assertSame(HealthStatus::Ok, $report->status(), implode(' | ', array_map(static fn ($c): string => $c->name . ': ' . $c->message, $report->problems())));
        self::assertSame([], $report->problems());
        self::assertGreaterThanOrEqual(9, count($report->checks));
    }

    public function testPendingMigrationsAreCritical(): void
    {
        $this->applyAllMigrations(skip: 2);

        $check = $this->check('migrations');

        self::assertSame(HealthStatus::Critical, $check->status);
        self::assertStringContainsString('2 migration(s) non appliquée(s)', $check->message);
        self::assertStringContainsString('make migrate', $check->message);
    }

    public function testAMissingMigrationsTableIsCritical(): void
    {
        $this->db->executeStatement('DROP TABLE doctrine_migration_versions');

        self::assertSame(HealthStatus::Critical, $this->check('migrations')->status);
    }

    /** @return iterable<string, array{string, string, HealthStatus}> */
    public static function providerRuns(): iterable
    {
        yield 'récent avec offres' => ['succeeded', '2', HealthStatus::Ok];
        yield 'récent sans nouveauté' => ['no_new_jobs', '2', HealthStatus::Ok];
        yield 'en retard' => ['succeeded', '14', HealthStatus::Warning];
        yield 'très en retard' => ['succeeded', '40', HealthStatus::Critical];
        yield 'dernier échec' => ['failed', '1', HealthStatus::Warning];
        yield 'dernier partiel' => ['partial', '1', HealthStatus::Warning];
    }

    #[DataProvider('providerRuns')]
    public function testASourceIsJudgedOnItsLastRun(string $status, string $hoursAgo, HealthStatus $expected): void
    {
        $this->recordRun('rss', $status, $hoursAgo, $status === 'failed' ? 'HTTP 503' : null);

        $check = $this->check('source rss');

        self::assertSame($expected, $check->status, $check->message);
    }

    public function testRepeatedFailuresAreCriticalAndShowTheError(): void
    {
        foreach (['3', '2', '1'] as $hours) {
            $this->recordRun('searxng', 'failed', $hours, 'instance injoignable');
        }

        $check = $this->check('source searxng');

        self::assertSame(HealthStatus::Critical, $check->status);
        self::assertStringContainsString('3 échecs consécutifs', $check->message);
        self::assertStringContainsString('instance injoignable', $check->message);
    }

    public function testASuccessBreaksTheFailureStreak(): void
    {
        $this->recordRun('searxng', 'failed', '4', 'x');
        $this->recordRun('searxng', 'failed', '3', 'x');
        $this->recordRun('searxng', 'succeeded', '2');
        $this->recordRun('searxng', 'failed', '1', 'x');

        self::assertSame(HealthStatus::Warning, $this->check('source searxng')->status, 'Un seul échec consécutif.');
    }

    public function testDisabledSourcesAreIgnoredAndNoRunAtAllIsAWarning(): void
    {
        self::assertSame(HealthStatus::Warning, $this->check('sources')->status);

        $this->recordRun('france_travail', 'disabled', '500');
        $checks = array_map(static fn (HealthCheck $c): string => $c->name, $this->service()->run($this->now)->checks);

        self::assertNotContains('source france_travail', $checks, 'Une source désactivée par configuration n\'est pas un problème.');
    }

    public function testAnAnalysisBacklogIsAWarning(): void
    {
        self::assertSame(HealthStatus::Ok, $this->check('analyses')->status);

        $this->persistPending(60, 1);
        $check = $this->check('analyses');

        self::assertSame(HealthStatus::Warning, $check->status);
        self::assertStringContainsString('60 en attente', $check->message);
    }

    public function testAnOldPendingAnalysisIsAWarningEvenWithAFewJobs(): void
    {
        $this->persistPending(1, 30);

        self::assertSame(HealthStatus::Warning, $this->check('analyses')->status);
    }

    public function testManyHeuristicFallbacksPointToTheLlm(): void
    {
        $this->persistAnalyzed(8, 'done');
        $this->persistAnalyzed(12, 'fallback');

        $names = array_map(static fn (HealthCheck $c): string => $c->name, $this->service()->run($this->now)->checks);
        self::assertContains('LLM', $names);
        self::assertStringContainsString('60 %', $this->check('LLM')->message);
    }

    public function testFewAnalysesNeverTriggerTheLlmWarning(): void
    {
        $this->persistAnalyzed(3, 'fallback');

        $names = array_map(static fn (HealthCheck $c): string => $c->name, $this->service()->run($this->now)->checks);

        self::assertNotContains('LLM', $names, 'Trop peu d\'analyses pour conclure.');
    }

    public function testAMissedPriorityNotificationIsAWarning(): void
    {
        $job = $this->eligibleJob(publishedAt: $this->now->modify('-10 hours'));
        $this->db->executeStatement('UPDATE job SET first_seen_at = :at WHERE id = :id', ['at' => $this->now->modify('-10 hours')->format('Y-m-d H:i:s'), 'id' => $job->getId()]);

        $check = $this->check('notifications');

        self::assertSame(HealthStatus::Warning, $check->status);
        self::assertStringContainsString('1 offre(s) prioritaire(s)', $check->message);
    }

    public function testNotificationsAreFineWhenSentRecentOrHandled(): void
    {
        $recent = $this->eligibleJob(publishedAt: $this->now->modify('-1 hours'), url: 'https://jobs.example/recent');
        $notified = $this->eligibleJob(url: 'https://jobs.example/notified');
        $notified->markAsNotified();
        $this->entityManager->flush();
        foreach ([$notified->getId()] as $id) {
            $this->db->executeStatement('UPDATE job SET first_seen_at = :at WHERE id = :id', ['at' => $this->now->modify('-10 hours')->format('Y-m-d H:i:s'), 'id' => $id]);
        }

        self::assertNotNull($recent->getId());

        self::assertSame(HealthStatus::Ok, $this->check('notifications')->status, 'Récente (pas encore en retard) ou déjà notifiée.');
    }

    public function testAnUnsentDueReminderIsAWarning(): void
    {
        $manager = self::getContainer()->get(ApplicationManager::class);
        $application = $manager->start($this->eligibleJob(), $this->now->modify('-30 days'));
        $manager->changeStatus($application, ApplicationStatus::Applied, $this->now->modify('-20 days'));
        $application->setFollowUpAt($this->now->modify('-10 hours'));
        $this->entityManager->flush();

        self::assertSame(HealthStatus::Warning, $this->check('rappels')->status);

        $application->markReminded($this->now);
        $this->entityManager->flush();
        self::assertSame(HealthStatus::Ok, $this->check('rappels')->status);
    }

    /** @return iterable<string, array{float, float, HealthStatus}> */
    public static function disks(): iterable
    {
        yield 'confortable' => [500.0, 1000.0, HealthStatus::Ok];
        yield 'à surveiller' => [80.0, 1000.0, HealthStatus::Warning];
        yield 'critique' => [30.0, 1000.0, HealthStatus::Critical];
        yield 'illisible' => [0.0, 0.0, HealthStatus::Warning];
    }

    #[DataProvider('disks')]
    public function testDiskSpaceThresholds(float $free, float $total, HealthStatus $expected): void
    {
        $this->disk = [$free, $total];

        self::assertSame($expected, $this->check('disque')->status);
    }

    public function testProfilesAreChecked(): void
    {
        self::assertSame(HealthStatus::Ok, $this->check('profils')->status);

        file_put_contents($this->profileDirectory . '/candidate.yaml', "competences_inconnues: [PHP]\n");
        self::getContainer()->get(ProfileRegistry::class)->reload();
        self::assertSame(HealthStatus::Critical, $this->check('profils')->status);

        unlink($this->profileDirectory . '/candidate.yaml');
        unlink($this->profileDirectory . '/search_profiles.yaml');
        self::getContainer()->get(ProfileRegistry::class)->reload();
        self::assertSame(HealthStatus::Warning, $this->check('profils')->status);
    }

    public function testACheckThatCrashesIsReportedAndDoesNotStopTheOthers(): void
    {
        $this->db->executeStatement('DROP TABLE notification_log');

        $report = $this->service()->run($this->now);
        $byName = array_column(array_map(static fn (HealthCheck $c): array => ['name' => $c->name, 'status' => $c->status, 'message' => $c->message], $report->checks), null, 'name');

        self::assertSame(HealthStatus::Critical, $byName['notifications']['status']);
        self::assertStringContainsString('make migrate', $byName['notifications']['message']);
        self::assertStringNotContainsString('SQLSTATE', $byName['notifications']['message'], 'Pas de SQL brut.');
        self::assertArrayHasKey('disque', $byName);
        self::assertArrayHasKey('profils', $byName);
    }

    private function persistPending(int $count, int $hoursOld): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $job = \App\Entity\Job::fromDTO(new \App\DTO\JobDto('En attente ' . $i, 'https://jobs.example/p' . $i . '-' . $hoursOld, 'D', 'test'));
            $this->entityManager->persist($job);
        }

        $this->entityManager->flush();
        $this->db->executeStatement("UPDATE job SET first_seen_at = :at, analysis_status = 'pending' WHERE title LIKE 'En attente%'", ['at' => $this->now->modify('-' . $hoursOld . ' hours')->format('Y-m-d H:i:s')]);
    }

    private function persistAnalyzed(int $count, string $status): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $job = \App\Entity\Job::fromDTO(new \App\DTO\JobDto('Analysée ' . $status . $i, 'https://jobs.example/a-' . $status . $i, 'D', 'test'));
            $this->entityManager->persist($job);
        }

        $this->entityManager->flush();
        $this->db->executeStatement('UPDATE job SET analysis_status = :s, analyzed_at = :at WHERE title LIKE :t', ['s' => $status, 'at' => $this->now->modify('-1 day')->format('Y-m-d H:i:s'), 't' => 'Analysée ' . $status . '%']);
    }
}
