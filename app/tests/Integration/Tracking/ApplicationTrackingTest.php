<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tracking;

use App\Entity\Job;
use App\Entity\Application;
use App\Tracking\JobUserState;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;
use App\Repository\ApplicationRepository;
use App\Tracking\InvalidApplicationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use App\Repository\ApplicationStatusChangeRepository;
use App\Tests\Integration\Notification\NotificationIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;

final class ApplicationTrackingTest extends NotificationIntegrationTestCase
{
    private ApplicationManager $manager;
    private ApplicationRepository $applications;
    private ApplicationStatusChangeRepository $history;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeNotificationProfile();
        $container = self::getContainer();
        $this->manager = $container->get(ApplicationManager::class);
        $this->applications = $container->get(ApplicationRepository::class);
        $this->history = $container->get(ApplicationStatusChangeRepository::class);
    }

    public function testStartingAnApplicationCreatesADraftWithItsFirstHistoryEntry(): void
    {
        $job = $this->eligibleJob();

        $application = $this->manager->start($job, $this->now, 'backend', 'backend-php');
        $this->entityManager->flush();

        self::assertSame(ApplicationStatus::Draft, $application->getStatus());
        self::assertSame('backend', $application->getSearchProfileId());
        self::assertSame('backend-php', $application->getCvVersionId());
        self::assertSame('CV Backend PHP', $application->getCvVersionLabel());
        $history = $this->history->findHistory($application);
        self::assertCount(1, $history);
        self::assertNull($history[0]->getFromStatus());
        self::assertSame(ApplicationStatus::Draft, $history[0]->getToStatus());
    }

    public function testAJobHasOnlyOneApplication(): void
    {
        $job = $this->eligibleJob();
        $this->manager->start($job, $this->now);
        $this->entityManager->flush();

        $this->expectException(InvalidApplicationException::class);
        $this->manager->start($job, $this->now);
    }

    public function testUnknownProfileAndCvAreRefusedNotInvented(): void
    {
        $job = $this->eligibleJob();

        foreach ([['inconnu', null], [null, 'cv-inconnu']] as [$profile, $cv]) {
            try {
                $this->manager->start($job, $this->now, $profile, $cv);
                self::fail('Référence inconnue acceptée.');
            } catch (InvalidApplicationException) {
                self::addToAssertionCount(1);
            }
        }
        $this->entityManager->flush();
        self::assertSame(0, $this->applications->count([]), 'Un refus ne crée rien.');
    }

    public function testStatusChangesAreRecordedInOrder(): void
    {
        $application = $this->manager->start($this->eligibleJob(), $this->now);
        $this->manager->changeStatus($application, ApplicationStatus::Applied, $this->now->modify('+1 day'), 'Envoyée');
        $this->manager->changeStatus($application, ApplicationStatus::Interview, $this->now->modify('+6 days'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->applications->find($application->getId());
        $history = $this->history->findHistory($reloaded);

        self::assertSame(ApplicationStatus::Interview, $reloaded->getStatus());
        self::assertSame(
            [ApplicationStatus::Draft, ApplicationStatus::Applied, ApplicationStatus::Interview],
            array_map(static fn ($change): ApplicationStatus => $change->getToStatus(), $history),
        );
        self::assertSame('Envoyée', $history[1]->getNote());
        self::assertSame(['interview' => 1], $this->applications->countByStatus());
    }

    public function testARefusedTransitionLeavesNoHistory(): void
    {
        $application = $this->manager->start($this->eligibleJob(), $this->now);
        $this->entityManager->flush();

        try {
            $this->manager->changeStatus($application, ApplicationStatus::Offer, $this->now);
            self::fail();
        } catch (InvalidApplicationException) {
            self::addToAssertionCount(1);
        }
        $this->entityManager->flush();

        self::assertCount(1, $this->history->findHistory($application));
    }

    // --- Protection contre la purge -------------------------------------------------------------

    private function oldJob(string $url): Job
    {
        $job = $this->eligibleJob(url: $url);
        $job->setCreatedAt($this->now->modify('-90 days'));
        $this->entityManager->flush();

        return $job;
    }

    public function testPurgeSparesJobsWithAnApplicationOrADecision(): void
    {
        $plain = $this->oldJob('https://jobs.example/plain');
        $tracked = $this->oldJob('https://jobs.example/tracked');
        $saved = $this->oldJob('https://jobs.example/saved');
        $ignored = $this->oldJob('https://jobs.example/ignored');
        $this->manager->start($tracked, $this->now);
        $this->manager->decide($saved, JobUserState::Saved, $this->now);
        $this->manager->decide($ignored, JobUserState::Ignored, $this->now);
        $this->entityManager->flush();
        $before = $this->now->modify('-30 days');

        self::assertSame(1, $this->jobs->countCreatedBefore($before));
        self::assertSame(3, $this->jobs->countProtectedCreatedBefore($before));

        self::assertSame(1, $this->jobs->deleteCreatedBefore($before));
        $this->entityManager->clear();

        self::assertNull($this->jobs->find($plain->getId()));
        foreach ([$tracked, $saved, $ignored] as $kept) {
            self::assertNotNull($this->jobs->find($kept->getId()));
        }
        self::assertSame(1, $this->applications->count([]));
        self::assertGreaterThan(0, $this->matches->count([]), 'Les résultats de matching des offres conservées le sont aussi.');
    }

    public function testRemovingTheDecisionMakesTheJobPurgeableAgain(): void
    {
        $job = $this->oldJob('https://jobs.example/a');
        $this->manager->decide($job, JobUserState::Archived, $this->now);
        $this->entityManager->flush();
        self::assertSame(0, $this->jobs->countCreatedBefore($this->now->modify('-30 days')));

        $this->manager->decide($job, null, $this->now);
        $this->entityManager->flush();

        self::assertSame(1, $this->jobs->countCreatedBefore($this->now->modify('-30 days')));
        self::assertNull($job->getUserStateAt());
    }

    public function testTheDevelopmentResetAlsoClearsApplications(): void
    {
        $application = $this->manager->start($this->eligibleJob(), $this->now);
        $this->manager->changeStatus($application, ApplicationStatus::Applied, $this->now);
        $this->entityManager->flush();

        $this->jobs->truncate();

        self::assertSame(0, $this->applications->count([]));
        self::assertSame(0, $this->history->count([]));
    }

    // --- Notifications -------------------------------------------------------------------------

    public function testADecidedJobIsNeverNotified(): void
    {
        $job = $this->eligibleJob(publishedAt: $this->now->modify('-1 hour'));
        $this->manager->decide($job, JobUserState::Ignored, $this->now);
        $this->entityManager->flush();

        self::assertFalse($this->matchNotifier()->notify($job, $this->now));
        self::assertSame(0, $this->digestNotifier()->send($this->now, true)->candidates);
        self::assertSame([], $this->telegram);
    }

    // --- Commandes -----------------------------------------------------------------------------

    private function command(string $name): CommandTester
    {
        return new CommandTester(new ConsoleApplication(self::$kernel)->find($name));
    }

    public function testTheCommandsTrackChangeAndListAnApplication(): void
    {
        $job = $this->eligibleJob();
        $id = $job->getId();

        $track = $this->command('app:applications:track');
        self::assertSame(Command::SUCCESS, $track->execute(['job' => (string) $id, '--cv' => 'backend-php', '--channel' => 'email', '--recruiter' => 'Camille Martin']));
        self::assertStringContainsString('protégée de la purge', $track->getDisplay());

        $status = $this->command('app:applications:status');
        $sentOn = new \DateTimeImmutable('-3 days');
        self::assertSame(Command::SUCCESS, $status->execute(['application' => '1', 'status' => 'applied', '--at' => $sentOn->format('Y-m-d'), '--message' => 'Bonjour, je candidate.']));
        self::assertStringContainsString('Brouillon → Postulé', $status->getDisplay());

        $list = $this->command('app:applications:list');
        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertStringContainsString('Postulé', $list->getDisplay());
        self::assertStringContainsString('CV Backend PHP', $list->getDisplay());
        self::assertStringContainsString($sentOn->format('d/m/Y'), $list->getDisplay());

        $application = $this->applications->find(1);
        self::assertInstanceOf(Application::class, $application);
        self::assertSame('Bonjour, je candidate.', $application->getMessageSent());
    }

    public function testTheCommandsRefuseInvalidInput(): void
    {
        $id = (string) $this->eligibleJob()->getId();
        $track = $this->command('app:applications:track');

        self::assertSame(Command::FAILURE, $track->execute(['job' => '9999']));
        self::assertSame(Command::INVALID, $this->command('app:applications:track')->execute(['job' => $id, '--channel' => 'pigeon']));
        self::assertSame(Command::FAILURE, $this->command('app:applications:track')->execute(['job' => $id, '--cv' => 'inconnu']));
        self::assertSame(0, $this->applications->count([]));

        $this->command('app:applications:track')->execute(['job' => $id]);
        self::assertSame(Command::INVALID, $this->command('app:applications:status')->execute(['application' => '1', 'status' => 'embauché']));
        $forbidden = $this->command('app:applications:status');
        self::assertSame(Command::FAILURE, $forbidden->execute(['application' => '1', 'status' => 'interview']));
        self::assertStringContainsString('Transition interdite', $forbidden->getDisplay());
        self::assertSame(Command::FAILURE, $this->command('app:applications:status')->execute(['application' => '1', 'status' => 'applied', '--at' => 'pas une date']));
        self::assertSame(Command::FAILURE, $this->command('app:applications:status')->execute(['application' => '1', 'status' => 'applied', '--at' => '2099-01-01']));
        self::assertSame(Command::INVALID, $this->command('app:applications:list')->execute(['--status' => 'inconnu']));
        self::assertSame(ApplicationStatus::Draft, $this->applications->find(1)->getStatus());
    }

    public function testTheDecideCommandSetsAndClearsTheState(): void
    {
        $job = $this->eligibleJob();

        self::assertSame(Command::SUCCESS, $this->command('app:jobs:decide')->execute(['job' => (string) $job->getId(), 'state' => 'saved']));
        $this->entityManager->refresh($job);
        self::assertSame(JobUserState::Saved, $job->getUserState());

        self::assertSame(Command::SUCCESS, $this->command('app:jobs:decide')->execute(['job' => (string) $job->getId(), 'state' => 'none']));
        $this->entityManager->refresh($job);
        self::assertNull($job->getUserState());

        self::assertSame(Command::INVALID, $this->command('app:jobs:decide')->execute(['job' => (string) $job->getId(), 'state' => 'perdue']));
        self::assertSame(Command::FAILURE, $this->command('app:jobs:decide')->execute(['job' => '9999', 'state' => 'saved']));
    }
}
