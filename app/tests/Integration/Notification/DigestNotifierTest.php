<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\DTO\ContractType;
use App\Entity\NotificationLog;
use App\AI\Extraction\JobRequirements;
use App\Notification\NotificationLevel;
use App\Notification\NotificationPolicy;

final class DigestNotifierTest extends NotificationIntegrationTestCase
{
    /** Date inconnue : priorité 81 pour une offre parfaite, donc « secondaire » avec ces seuils. */
    private function secondaryPolicy(string $interestingMode = 'immediate', int $maxItems = 15): NotificationPolicy
    {
        return new NotificationPolicy(priorityThreshold: 95, interestingThreshold: 90, secondaryThreshold: 60, interestingMode: $interestingMode, secondaryMode: 'digest', digestMaxItems: $maxItems);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeNotificationProfile();
    }

    public function testSendsOneSilentDigestAndMarksTheIncludedOffers(): void
    {
        $first = $this->eligibleJob('Offre A', url: 'https://jobs.example/a');
        $second = $this->eligibleJob('Offre B', url: 'https://jobs.example/b');

        $result = $this->digestNotifier($this->secondaryPolicy())->send($this->now);
        $this->entityManager->flush();

        self::assertTrue($result->sent);
        self::assertSame(2, $result->included);
        self::assertSame(0, $result->remaining());
        self::assertCount(1, $this->telegram);
        self::assertTrue($this->telegram[0]['disable_notification']);
        self::assertTrue($this->telegram[0]['link_preview_options']['is_disabled']);
        self::assertStringContainsString('Récapitulatif — 2 offre(s)', $this->telegram[0]['text']);
        self::assertStringContainsString('Offre A', $this->telegram[0]['text']);
        self::assertStringContainsString('📌 Offres secondaires', $this->telegram[0]['text']);

        $logs = $this->logs->findAll();
        self::assertCount(2, $logs);
        self::assertSame(NotificationLog::KIND_DIGEST, $logs[0]->getKind());
        self::assertSame(NotificationLevel::Secondary, $logs[0]->getLevel());
        self::assertNull($first->getNotifiedAt(), 'Un récapitulatif ne marque pas l\'offre comme notifiée individuellement.');
        self::assertNull($second->getNotifiedAt());
    }

    public function testAnOfferAlreadyInADigestIsNeverSentAgain(): void
    {
        $this->eligibleJob();
        $notifier = $this->digestNotifier($this->secondaryPolicy());

        $notifier->send($this->now);
        $this->entityManager->flush();
        $second = $notifier->send($this->now->modify('+1 day'));

        self::assertSame(0, $second->candidates);
        self::assertFalse($second->sent);
        self::assertCount(1, $this->telegram);
    }

    public function testADigestedOfferIsNotNotifiedImmediatelyLater(): void
    {
        $job = $this->eligibleJob();
        $this->digestNotifier($this->secondaryPolicy())->send($this->now);
        $this->entityManager->flush();

        self::assertFalse($this->matchNotifier(new NotificationPolicy(priorityThreshold: 70))->notify($job, $this->now));
        self::assertCount(1, $this->telegram);
    }

    public function testOffersBeyondTheLimitStayForTheNextDigest(): void
    {
        foreach (range(1, 5) as $i) {
            $this->eligibleJob('Offre ' . $i, url: 'https://jobs.example/' . $i);
        }

        $notifier = $this->digestNotifier($this->secondaryPolicy(maxItems: 2));

        $first = $notifier->send($this->now);
        $this->entityManager->flush();
        self::assertSame([2, 2], [$first->included, $first->candidates]);

        $second = $notifier->send($this->now);
        $this->entityManager->flush();
        $third = $notifier->send($this->now);
        $this->entityManager->flush();

        self::assertSame(2, $second->included);
        self::assertSame(1, $third->included);
        self::assertSame(5, $this->logs->count([]), 'Chaque offre est reprise une fois, ni plus ni moins.');
        self::assertSame(0, $notifier->send($this->now)->candidates);
    }

    public function testATelegramFailureMarksNothingSoTheOffersAreRetried(): void
    {
        $this->eligibleJob();
        $notifier = $this->digestNotifier($this->secondaryPolicy());
        $this->telegramFails = true;

        $failed = $notifier->send($this->now);
        $this->entityManager->flush();

        self::assertFalse($failed->sent);
        self::assertSame(1, $failed->candidates);
        self::assertSame(0, $this->logs->count([]));

        $this->telegramFails = false;
        self::assertTrue($notifier->send($this->now)->sent);
    }

    public function testADryRunShowsTheMessageButSendsAndMarksNothing(): void
    {
        $this->eligibleJob('Offre visible');

        $result = $this->digestNotifier($this->secondaryPolicy())->send($this->now, true);
        $this->entityManager->flush();

        self::assertFalse($result->sent);
        self::assertSame(1, $result->included);
        self::assertStringContainsString('Offre visible', $result->text);
        self::assertSame([], $this->telegram);
        self::assertSame(0, $this->logs->count([]));
    }

    public function testTheMaxItemsArgumentOverridesTheConfiguration(): void
    {
        foreach (range(1, 3) as $i) {
            $this->eligibleJob('Offre ' . $i, url: 'https://jobs.example/' . $i);
        }

        $result = $this->digestNotifier($this->secondaryPolicy())->send($this->now, true, 1);

        self::assertSame(1, $result->candidates);
    }

    public function testOnlyOffersConfiguredForTheDigestAreIncluded(): void
    {
        $this->eligibleJob();
        // Offre intéressante (priorité 81 ≥ 70) en mode immédiat : ce n'est pas l'affaire du récapitulatif.
        $immediateInteresting = new NotificationPolicy(priorityThreshold: 95, interestingThreshold: 70, secondaryThreshold: 60, interestingMode: 'immediate');
        $digestInteresting = new NotificationPolicy(priorityThreshold: 95, interestingThreshold: 70, secondaryThreshold: 60, interestingMode: 'digest');
        $off = new NotificationPolicy(priorityThreshold: 95, interestingThreshold: 70, secondaryThreshold: 60, interestingMode: 'off', secondaryMode: 'off');

        self::assertSame(0, $this->digestNotifier($immediateInteresting)->send($this->now, true)->candidates);
        self::assertSame(0, $this->digestNotifier($off)->send($this->now, true)->candidates);

        $withInteresting = $this->digestNotifier($digestInteresting)->send($this->now, true);
        self::assertSame(1, $withInteresting->candidates);
        self::assertStringContainsString('⭐ Offres intéressantes', $withInteresting->text);
    }

    public function testExcludesDuplicatesNotifiedOldAndIncompatibleOffers(): void
    {
        $kept = $this->eligibleJob('Retenue', url: 'https://jobs.example/kept');
        $duplicate = $this->eligibleJob('Doublon', url: 'https://jobs.example/dup');
        $duplicate->markProbableDuplicateOf((int) $kept->getId());
        $legacy = $this->eligibleJob('Déjà notifiée', url: 'https://jobs.example/legacy');
        $legacy->markAsNotified();
        $old = $this->eligibleJob('Ancienne', url: 'https://jobs.example/old');
        $this->pushFirstSeenBack($old, 10);
        $this->eligibleJob('COBOL', url: 'https://jobs.example/cobol', requirements: new JobRequirements(requiredSkills: ['COBOL', 'Fortran'], contractTypes: [ContractType::Cdi]));
        $this->entityManager->flush();

        $result = $this->digestNotifier($this->secondaryPolicy())->send($this->now, true);

        self::assertSame(1, $result->candidates);
        self::assertStringContainsString('Retenue', $result->text);
        foreach (['Doublon', 'Déjà notifiée', 'Ancienne', 'COBOL'] as $excluded) {
            self::assertStringNotContainsString($excluded, $result->text);
        }
    }

    public function testAJobWithSeveralCompatibleProfilesAppearsOnce(): void
    {
        $this->writeProfile(self::CANDIDATE_WITH_CV, self::BACKEND_ONLY . "\n  second:\n    titles: [\"Développeur\"]\n    primary_skills: [PHP]\n    min_match_score: 60\n");
        $this->jobMatcher()->reset();
        $this->eligibleJob('Offre double');

        $result = $this->digestNotifier($this->secondaryPolicy())->send($this->now, true);

        self::assertSame(1, $result->candidates);
        self::assertSame(1, substr_count($result->text, 'Offre double'));
    }

    public function testTheDigestIsBoundedAndTheRestIsAnnounced(): void
    {
        foreach (range(1, 40) as $i) {
            $this->eligibleJob(str_repeat('Développeur back-end PHP très long ', 3) . $i, url: 'https://jobs.example/' . $i);
        }

        $result = $this->digestNotifier($this->secondaryPolicy(maxItems: 40))->send($this->now);
        $this->entityManager->flush();

        self::assertTrue($result->sent);
        self::assertLessThan(40, $result->included);
        self::assertLessThan(4096, mb_strlen($this->telegram[0]['text']));
        self::assertSame($result->included, $this->logs->count([]), 'Seules les offres reprises sont marquées.');
        self::assertStringContainsString(sprintf('… et %d autre(s) offre(s)', 40 - $result->included), $this->telegram[0]['text']);
    }

    public function testPurgingJobsAlsoRemovesTheirNotificationLog(): void
    {
        $job = $this->eligibleJob();
        $job->setCreatedAt($this->now->modify('-60 days'));
        $this->entityManager->flush();
        $this->digestNotifier($this->secondaryPolicy())->send($this->now);
        $this->entityManager->flush();
        self::assertSame(1, $this->logs->count([]));

        $this->jobs->deleteCreatedBefore($this->now->modify('-30 days'));

        self::assertSame(0, $this->logs->count([]));
    }

    public function testTruncatingJobsAlsoRemovesTheirNotificationLog(): void
    {
        $this->eligibleJob();
        $this->digestNotifier($this->secondaryPolicy())->send($this->now);
        $this->entityManager->flush();

        $this->jobs->truncate();

        self::assertSame(0, $this->logs->count([]));
    }
}
