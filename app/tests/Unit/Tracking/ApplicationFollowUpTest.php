<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tracking;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Entity\Application;
use PHPUnit\Framework\TestCase;
use App\Tracking\ApplicationStatus;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApplicationFollowUpTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-12-10 09:00:00');
    }

    private function application(ApplicationStatus $status = ApplicationStatus::Applied): Application
    {
        $application = new Application(Job::fromDTO(new JobDto('Dév', 'https://jobs.example/1', 'D', 'test')), $this->now->modify('-20 days'));
        $path = [ApplicationStatus::Draft];
        if ($status !== ApplicationStatus::Draft) {
            $application->transitionTo(ApplicationStatus::Applied, $this->now->modify('-15 days'));
            if ($status !== ApplicationStatus::Applied) {
                $application->transitionTo($status, $this->now->modify('-10 days'));
            }
        }
        self::assertNotEmpty($path);

        return $application;
    }

    /** @return iterable<string, array{ApplicationStatus, string, bool}> */
    public static function dueCases(): iterable
    {
        yield 'envoyée, date atteinte' => [ApplicationStatus::Applied, '-1 day', true];
        yield 'envoyée, aujourd\'hui même' => [ApplicationStatus::Applied, 'now', true];
        yield 'envoyée, date à venir' => [ApplicationStatus::Applied, '+1 day', false];
        yield 'entretien en cours' => [ApplicationStatus::Interview, '-1 day', true];
        yield 'sans réponse' => [ApplicationStatus::NoResponse, '-3 days', true];
        yield 'brouillon : rien n\'a été envoyé' => [ApplicationStatus::Draft, '-1 day', false];
        yield 'refusée' => [ApplicationStatus::Rejected, '-1 day', false];
        yield 'retirée' => [ApplicationStatus::Withdrawn, '-1 day', false];
    }

    #[DataProvider('dueCases')]
    public function testWhichApplicationsAreDue(ApplicationStatus $status, string $date, bool $due): void
    {
        $application = $this->application($status);
        $application->setFollowUpAt($status === ApplicationStatus::Draft ? $this->now->modify($date) : max($this->now->modify($date), $application->getAppliedAt() ?? $this->now->modify($date)));

        self::assertSame($due, $application->isFollowUpDue($this->now));
        self::assertSame($due, $application->needsReminder($this->now));
    }

    public function testNoDateMeansNoReminder(): void
    {
        self::assertFalse($this->application()->isFollowUpDue($this->now));
    }

    public function testAReminderIsOnlyDueOncePerDate(): void
    {
        $application = $this->application();
        $application->setFollowUpAt($this->now->modify('-1 day'));
        self::assertTrue($application->needsReminder($this->now));

        $application->markReminded($this->now);

        self::assertFalse($application->needsReminder($this->now));
        self::assertTrue($application->isFollowUpDue($this->now), 'La relance reste due tant qu\'elle n\'est pas faite.');
    }

    public function testChangingTheDateRearmsTheReminderButSavingTheSameDateDoesNot(): void
    {
        $application = $this->application();
        $application->setFollowUpAt($this->now->modify('-2 days'));
        $application->markReminded($this->now);

        $application->setFollowUpAt($this->now->modify('-2 days'));
        self::assertFalse($application->needsReminder($this->now), 'Enregistrer le formulaire sans changer la date ne relance pas le rappel.');

        $application->setFollowUpAt($this->now->modify('-1 day'));
        self::assertTrue($application->needsReminder($this->now));
    }

    public function testSnoozeAndComplete(): void
    {
        $application = $this->application();
        $application->setFollowUpAt($this->now->modify('-1 day'));
        $application->markReminded($this->now);

        $application->snoozeFollowUp($this->now, 3);
        self::assertSame('2026-12-13', $application->getFollowUpAt()?->format('Y-m-d'));
        self::assertNull($application->getFollowUpRemindedAt());
        self::assertFalse($application->isFollowUpDue($this->now));
        self::assertTrue($application->needsReminder($this->now->modify('+3 days')));

        $application->completeFollowUp($this->now);
        self::assertNull($application->getFollowUpAt());
        self::assertSame($this->now, $application->getLastContactAt());
        self::assertFalse($application->isFollowUpDue($this->now->modify('+30 days')));
    }
}
