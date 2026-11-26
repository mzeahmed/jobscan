<?php

declare(strict_types=1);

namespace App\Tests\Integration\Health;

use App\Health\HealthStateStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use App\Tests\Integration\Notification\NotificationIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;

final class HealthCheckCommandTest extends NotificationIntegrationTestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeNotificationProfile();
        $db = $this->entityManager->getConnection();
        $db->executeStatement('CREATE TABLE IF NOT EXISTS doctrine_migration_versions (version VARCHAR(191) PRIMARY KEY, executed_at DATETIME DEFAULT NULL, execution_time INTEGER DEFAULT NULL)');
        foreach (glob(self::getContainer()->getParameter('kernel.project_dir') . '/migrations/Version*.php') ?: [] as $file) {
            $db->executeStatement('INSERT OR IGNORE INTO doctrine_migration_versions (version) VALUES (:v)', ['v' => 'DoctrineMigrations\\' . basename($file, '.php')]);
        }

        $this->stateFile = sys_get_temp_dir() . '/jobscan-health-' . bin2hex(random_bytes(4)) . '/state';
        self::getContainer()->set(HealthStateStore::class, new HealthStateStore($this->stateFile));
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
            rmdir(dirname($this->stateFile));
        }

        parent::tearDown();
    }

    private function command(): CommandTester
    {
        return new CommandTester(new ConsoleApplication(self::$kernel)->find('app:health:check'));
    }

    private function breakSomething(): void
    {
        // Une source en échec répété : état critique.
        foreach ([3, 2, 1] as $hours) {
            $this->entityManager->getConnection()->insert('provider_run', ['provider' => 'rss', 'started_at' => new \DateTimeImmutable()->modify("-$hours hours")->format('Y-m-d H:i:s'), 'duration_ms' => 1, 'status' => 'failed', 'fetched_count' => 0, 'new_count' => 0, 'duplicate_count' => 0, 'error_count' => 1, 'last_error' => 'HTTP 503']);
        }
    }

    private function fixIt(): void
    {
        $db = $this->entityManager->getConnection();
        $db->executeStatement('DELETE FROM provider_run');
        $db->insert('provider_run', ['provider' => 'rss', 'started_at' => new \DateTimeImmutable('-30 minutes')->format('Y-m-d H:i:s'), 'duration_ms' => 1, 'status' => 'succeeded', 'fetched_count' => 1, 'new_count' => 1, 'duplicate_count' => 0, 'error_count' => 0, 'last_error' => null]);
    }

    public function testAHealthyInstallationExitsZero(): void
    {
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('JOBSCAN — Santé', $tester->getDisplay());
        self::assertStringNotContainsString('✖', $tester->getDisplay());
    }

    public function testACriticalStateExitsNonZeroAndNamesTheProblem(): void
    {
        $this->breakSomething();

        $tester = $this->command();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('source rss', $tester->getDisplay());
        self::assertStringContainsString('3 échecs consécutifs', $tester->getDisplay());
    }

    public function testAWarningOnlyFailsInStrictMode(): void
    {
        // « Aucune collecte enregistrée » : avertissement, pas critique.
        self::assertSame(Command::SUCCESS, $this->command()->execute([]));
        self::assertSame(Command::FAILURE, $this->command()->execute(['--strict' => true]));
    }

    public function testJsonOutputIsValid(): void
    {
        $this->breakSomething();

        $tester = $this->command();
        $tester->execute(['--format' => 'json']);
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('critical', $data['status']);
        $names = array_column($data['checks'], 'name');
        self::assertContains('base de données', $names);
        self::assertContains('source rss', $names);
    }

    public function testInvalidFormatIsRefused(): void
    {
        self::assertSame(Command::INVALID, $this->command()->execute(['--format' => 'xml']));
    }

    public function testAnAlertIsSentOnceWhenAProblemAppearsAndOnceWhenItIsFixed(): void
    {
        $this->breakSomething();

        $this->command()->execute(['--alert' => true]);
        self::assertCount(1, $this->telegram);
        self::assertStringContainsString('problème', $this->telegram[0]['text']);
        self::assertStringContainsString('source rss', $this->telegram[0]['text']);

        $this->command()->execute(['--alert' => true]);
        $this->command()->execute(['--alert' => true]);
        self::assertCount(1, $this->telegram, 'Même problème : jamais de répétition.');

        $this->fixIt();
        $this->command()->execute(['--alert' => true]);
        self::assertCount(2, $this->telegram);
        self::assertStringContainsString('tout est rétabli', $this->telegram[1]['text']);
        self::assertStringNotContainsString('✖', $this->telegram[1]['text']);
    }

    public function testNoAlertOnAHealthyFirstRun(): void
    {
        $this->entityManager->getConnection()->insert('provider_run', ['provider' => 'rss', 'started_at' => new \DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s'), 'duration_ms' => 1, 'status' => 'succeeded', 'fetched_count' => 1, 'new_count' => 1, 'duplicate_count' => 0, 'error_count' => 0, 'last_error' => null]);

        $this->command()->execute(['--alert' => true]);

        self::assertSame([], $this->telegram);
    }

    public function testAChangeOfSeverityAlertsAgain(): void
    {
        $this->entityManager->getConnection()->insert('provider_run', ['provider' => 'rss', 'started_at' => new \DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s'), 'duration_ms' => 1, 'status' => 'failed', 'fetched_count' => 0, 'new_count' => 0, 'duplicate_count' => 0, 'error_count' => 1, 'last_error' => 'x']);
        $this->command()->execute(['--alert' => true]);
        self::assertCount(1, $this->telegram, 'Un avertissement.');

        $this->breakSomething();
        $this->command()->execute(['--alert' => true]);

        self::assertCount(2, $this->telegram, 'Devenu critique.');
    }

    public function testAFailedAlertIsRetriedAtTheNextCheck(): void
    {
        $this->breakSomething();
        $this->telegramFails = true;
        $this->command()->execute(['--alert' => true]);
        self::assertSame([], $this->telegram);
        self::assertNull(new HealthStateStore($this->stateFile)->read(), 'Rien n\'est mémorisé tant que Telegram n\'a pas reçu l\'alerte.');

        $this->telegramFails = false;
        $this->command()->execute(['--alert' => true]);

        self::assertCount(1, $this->telegram);
    }

    public function testAnAlertEscapesTextComingFromErrors(): void
    {
        foreach ([3, 2, 1] as $hours) {
            $this->entityManager->getConnection()->insert('provider_run', ['provider' => 'rss', 'started_at' => new \DateTimeImmutable()->modify("-$hours hours")->format('Y-m-d H:i:s'), 'duration_ms' => 1, 'status' => 'failed', 'fetched_count' => 0, 'new_count' => 0, 'duplicate_count' => 0, 'error_count' => 1, 'last_error' => '<b>piège</b> & Cie']);
        }

        $this->command()->execute(['--alert' => true]);

        self::assertStringContainsString('&lt;b&gt;piège&lt;/b&gt; &amp; Cie', $this->telegram[0]['text']);
    }
}
