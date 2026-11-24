<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tracking;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Entity\Application;
use PHPUnit\Framework\TestCase;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationChannel;
use App\Tracking\InvalidApplicationException;

final class ApplicationTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-12-01 10:00:00');
    }

    private function application(): Application
    {
        return new Application(Job::fromDTO(new JobDto('Dév PHP', 'https://jobs.example/1', 'Description', 'test')), $this->now);
    }

    public function testStartsAsADraftWithNoDates(): void
    {
        $application = $this->application();

        self::assertSame(ApplicationStatus::Draft, $application->getStatus());
        self::assertNull($application->getAppliedAt());
        self::assertNull($application->getRespondedAt());
        self::assertNull($application->getLastContactAt());
    }

    public function testApplyingRecordsTheSendDateAndLastContact(): void
    {
        $application = $this->application();
        $at = $this->now->modify('+1 day');

        $change = $application->transitionTo(ApplicationStatus::Applied, $at, '  Envoyée par email  ');

        self::assertSame($at, $application->getAppliedAt());
        self::assertSame($at, $application->getLastContactAt());
        self::assertNull($application->getRespondedAt());
        self::assertSame(ApplicationStatus::Draft, $change->getFromStatus());
        self::assertSame(ApplicationStatus::Applied, $change->getToStatus());
        self::assertSame('Envoyée par email', $change->getNote());
    }

    public function testTheFirstResponseIsKeptEvenAfterLaterOnes(): void
    {
        $application = $this->application();
        $application->transitionTo(ApplicationStatus::Applied, $this->now->modify('+1 day'));
        $first = $this->now->modify('+5 days');

        $application->transitionTo(ApplicationStatus::Interview, $first);
        $last = $this->now->modify('+9 days');
        $application->transitionTo(ApplicationStatus::Offer, $last);

        self::assertSame($first, $application->getRespondedAt());
        self::assertSame($last, $application->getLastContactAt());
    }

    public function testNoResponseIsNotAContactNorAResponse(): void
    {
        $application = $this->application();
        $sent = $this->now->modify('+1 day');
        $application->transitionTo(ApplicationStatus::Applied, $sent);

        $application->transitionTo(ApplicationStatus::NoResponse, $this->now->modify('+20 days'));

        self::assertNull($application->getRespondedAt());
        self::assertSame($sent, $application->getLastContactAt());
    }

    public function testAForbiddenTransitionIsRefusedAndChangesNothing(): void
    {
        $application = $this->application();

        try {
            $application->transitionTo(ApplicationStatus::Interview, $this->now);
            self::fail('Transition interdite acceptée.');
        } catch (InvalidApplicationException $e) {
            self::assertStringContainsString('Brouillon → Entretien', $e->getMessage());
        }

        self::assertSame(ApplicationStatus::Draft, $application->getStatus());
        self::assertNull($application->getRespondedAt());
    }

    public function testAFinalStatusSaysSo(): void
    {
        $application = $this->application();
        $application->transitionTo(ApplicationStatus::Withdrawn, $this->now);

        $this->expectException(InvalidApplicationException::class);
        $this->expectExceptionMessage('statut final');

        $application->transitionTo(ApplicationStatus::Applied, $this->now);
    }

    public function testADateBeforeThePreviousChangeIsRefused(): void
    {
        $application = $this->application();
        $application->transitionTo(ApplicationStatus::Applied, $this->now->modify('+3 days'));

        $this->expectException(InvalidApplicationException::class);
        $this->expectExceptionMessage('précède');

        $application->transitionTo(ApplicationStatus::Interview, $this->now->modify('+1 day'));
    }

    public function testAnApplicationSentBeforeTheDraftWasOpenedCanBeRecorded(): void
    {
        $application = $this->application();
        $sent = $this->now->modify('-6 days');

        $application->transitionTo(ApplicationStatus::Applied, $sent);

        self::assertSame($sent, $application->getAppliedAt());
        self::assertSame($sent, $application->getStatusChangedAt());
    }

    public function testTheFollowUpCannotPrecedeTheApplicationDate(): void
    {
        $application = $this->application();
        $application->transitionTo(ApplicationStatus::Applied, $this->now->modify('+2 days'));

        $application->setFollowUpAt($this->now->modify('+9 days'));
        self::assertNotNull($application->getFollowUpAt());

        $this->expectException(InvalidApplicationException::class);
        $application->setFollowUpAt($this->now);
    }

    public function testFreeTextIsTrimmedEmptyBecomesNullAndLengthIsBounded(): void
    {
        $application = $this->application();

        $application->setRecruiterName('  Camille Martin ');
        $application->setRecruiterContact('   ');
        $application->setNotes(str_repeat('é', 25000));
        $application->setChannel(ApplicationChannel::Email);

        self::assertSame('Camille Martin', $application->getRecruiterName());
        self::assertNull($application->getRecruiterContact());
        self::assertSame(20000, mb_strlen((string) $application->getNotes()));
        self::assertSame(ApplicationChannel::Email, $application->getChannel());
    }

    public function testTheCvLabelIsOnlyKeptWithAnId(): void
    {
        $application = $this->application();

        $application->setCvVersion('backend', 'CV Backend');
        self::assertSame('CV Backend', $application->getCvVersionLabel());

        $application->setCvVersion(null, 'orphelin');
        self::assertNull($application->getCvVersionId());
        self::assertNull($application->getCvVersionLabel());
    }
}
