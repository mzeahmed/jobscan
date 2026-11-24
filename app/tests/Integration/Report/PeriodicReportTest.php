<?php

declare(strict_types=1);

namespace App\Tests\Integration\Report;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Report\PeriodicReport;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use App\Tests\Integration\Notification\NotificationIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;

final class PeriodicReportTest extends NotificationIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeNotificationProfile();
    }

    private function report(): PeriodicReport
    {
        return self::getContainer()->get(PeriodicReport::class);
    }

    /** Les offres de test sont créées un instant après `$this->now` : le rapport est établi un peu plus tard. */
    private function later(): \DateTimeImmutable
    {
        return $this->now->modify('+1 hour');
    }

    private function seenAgo(Job $job, int $days): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE job SET first_seen_at = :at WHERE id = :id', ['at' => $this->now->modify("-$days days")->format('Y-m-d H:i:s'), 'id' => $job->getId()]);
    }

    private function seed(): void
    {
        $manager = self::getContainer()->get(ApplicationManager::class);
        $recent = $this->eligibleJob('Offre récente', url: 'https://jobs.example/recent');
        $old = $this->eligibleJob('Offre ancienne', url: 'https://jobs.example/old');
        $this->seenAgo($recent, 2);
        $this->seenAgo($old, 20);
        $duplicate = $this->eligibleJob('Doublon', url: 'https://jobs.example/dup');
        $duplicate->markProbableDuplicateOf((int) $recent->getId());
        $recent->markAsNotified();

        $tracked = $this->eligibleJob('Offre suivie', url: 'https://jobs.example/tracked');
        $application = $manager->start($tracked, $this->now->modify('-30 days'));
        $manager->changeStatus($application, ApplicationStatus::Applied, $this->now->modify('-3 days'));
        $manager->changeStatus($application, ApplicationStatus::Interview, $this->now->modify('-1 days'));
        $application->setFollowUpAt($this->now->modify('-1 hour'));

        $oldApplication = $manager->start($this->eligibleJob('Ancienne candidature', url: 'https://jobs.example/oldapp'), $this->now->modify('-60 days'));
        $manager->changeStatus($oldApplication, ApplicationStatus::Applied, $this->now->modify('-40 days'));
        $manager->changeStatus($oldApplication, ApplicationStatus::Rejected, $this->now->modify('-35 days'));
        $this->entityManager->flush();
    }

    public function testThePeriodCountsOnlyWhatHappenedInIt(): void
    {
        $this->seed();

        $data = $this->report()->build($this->later(), 7);

        self::assertSame(3, $data->collected, 'Récente, suivie et celle de la candidature close ; ni l\'ancienne (20 jours) ni le doublon.');
        self::assertSame(1, $data->applicationsSent);
        self::assertSame(1, $data->responses);
        self::assertSame(1, $data->interviews);
        self::assertSame(0, $data->offers);
        self::assertSame(1, $data->dueFollowUps);
        self::assertGreaterThanOrEqual(1, $data->notified);
        self::assertSame(7, $data->days());
    }

    public function testTheLongerPeriodIncludesOlderEvents(): void
    {
        $this->seed();

        $data = $this->report()->build($this->later(), 90);

        self::assertSame(2, $data->applicationsSent);
        self::assertSame(2, $data->responses);
        self::assertGreaterThan($this->report()->build($this->later(), 7)->collected, $data->collected);
    }

    public function testOffersToSortExcludeDecidedAndTrackedOnes(): void
    {
        $this->seed();

        $titles = array_column($this->report()->build($this->later(), 7)->toSort, 'title');

        self::assertContains('Offre récente', $titles);
        self::assertNotContains('Offre suivie', $titles);
        self::assertNotContains('Doublon', $titles);
    }

    public function testMarkdownHoldsEverySectionAndTheProblems(): void
    {
        $this->seed();

        $markdown = $this->report()->markdown($this->report()->build($this->later(), 7));

        foreach (['# Rapport Jobscan — 7 derniers jours', '## Collecte', '## Candidatures', '## À trier', '## Santé', 'Relances à faire : **1**', '[Offre récente](https://jobs.example/recent)'] as $expected) {
            self::assertStringContainsString($expected, $markdown, $expected);
        }
    }

    public function testTheTelegramSummaryIsEscapedBoundedAndFreeOfPersonalData(): void
    {
        $manager = self::getContainer()->get(ApplicationManager::class);
        $job = Job::fromDTO(new JobDto('Dév <script>alert(1)</script> & Cie', 'https://jobs.example/xss', 'Poste PHP Symfony', 'test', null, company: 'Evil "Corp" <b>'));
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $this->jobMatcher()->matchJob($job, $this->now);
        $application = $manager->start($this->eligibleJob('Autre', url: 'https://jobs.example/other'), $this->now);
        $application->setRecruiterName('Camille Secrète');
        $application->setRecruiterContact('camille@secret.test');
        $this->entityManager->flush();

        $data = $this->report()->build($this->later(), 7);
        $text = $this->report()->telegram($data);

        self::assertStringNotContainsString('<script>', $text);
        self::assertStringNotContainsString('Camille', $text);
        self::assertStringNotContainsString('secret.test', $text);
        self::assertLessThan(4096, mb_strlen($text));
        self::assertStringContainsString('📊 <b>Rapport Jobscan — 7 jours</b>', $text);
    }

    // --- Commande ----------------------------------------------------------------------------------

    private function command(): CommandTester
    {
        return new CommandTester(new ConsoleApplication(self::$kernel)->find('app:report:weekly'));
    }

    public function testTheCommandPrintsMarkdownByDefault(): void
    {
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('# Rapport Jobscan — 7 derniers jours', $tester->getDisplay());
        self::assertSame([], $this->telegram);
    }

    public function testTheCommandWritesAFileAndSendsTelegramOnRequest(): void
    {
        $file = sys_get_temp_dir() . '/jobscan-report-' . bin2hex(random_bytes(4)) . '.md';

        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '30', '--output' => $file, '--telegram' => true]));

        self::assertStringContainsString('# Rapport Jobscan — 30 derniers jours', (string) file_get_contents($file));
        self::assertTrue($this->telegram[0]['disable_notification'], 'Le rapport est silencieux.');
        self::assertStringContainsString('Rapport Jobscan — 30 jours', $this->telegram[0]['text']);
        unlink($file);
    }

    public function testTheCommandReportsTelegramAndFileFailures(): void
    {
        $this->telegramFails = true;
        self::assertSame(Command::FAILURE, $this->command()->execute(['--telegram' => true]));
        self::assertSame(Command::FAILURE, $this->command()->execute(['--output' => sys_get_temp_dir()]), 'Un dossier n\'est pas un fichier.');
    }

    public function testTheCommandValidatesTheDuration(): void
    {
        foreach (['0', '-3', 'abc', '400'] as $days) {
            self::assertSame(Command::INVALID, $this->command()->execute(['--days' => $days]), $days);
        }
    }
}
