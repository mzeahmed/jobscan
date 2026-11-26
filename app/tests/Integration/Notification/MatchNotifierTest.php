<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use App\DTO\AnalysisStatus;
use App\Entity\NotificationLog;
use App\AI\Extraction\JobRequirements;
use App\AI\Extraction\ExtractionSource;
use App\Notification\NotificationLevel;
use App\Profile\Model\RemotePreference;
use App\Notification\NotificationPolicy;

final class MatchNotifierTest extends NotificationIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->writeNotificationProfile();
    }

    public function testAPriorityOpportunityIsNotifiedImmediatelyWithEveryField(): void
    {
        $job = $this->eligibleJob(publishedAt: $this->now->modify('-1 hour'));

        self::assertTrue($this->matchNotifier()->notify($job, $this->now));
        $this->entityManager->flush();

        self::assertCount(1, $this->telegram);
        $payload = $this->telegram[0];
        self::assertFalse($payload['disable_notification'], 'Une opportunité prioritaire est sonore.');
        foreach (['Opportunité prioritaire', 'Développeur back-end PHP', 'Compatibilité <b>100</b>', 'Fraîcheur <b>100</b>', 'Priorité <b>100</b>', 'Profil : Backend PHP / Symfony · CV : CV Backend PHP', 'Contrat : cdi', 'https://jobs.example/1'] as $expected) {
            self::assertStringContainsString($expected, $payload['text'], $expected);
        }

        self::assertNotNull($job->getNotifiedAt());
        $logs = $this->logs->findAll();
        self::assertCount(1, $logs);
        self::assertSame(NotificationLog::KIND_IMMEDIATE, $logs[0]->getKind());
        self::assertSame(NotificationLevel::Priority, $logs[0]->getLevel());
        self::assertSame('backend', $logs[0]->getSearchProfileId());
        self::assertSame(100, $logs[0]->getPriorityScore());
    }

    public function testAnOfferIsNeverNotifiedTwice(): void
    {
        $job = $this->eligibleJob(publishedAt: $this->now->modify('-1 hour'));
        $notifier = $this->matchNotifier();

        self::assertTrue($notifier->notify($job, $this->now));
        $this->entityManager->flush();

        self::assertFalse($notifier->notify($job, $this->now));
        self::assertFalse($notifier->notify($job, $this->now->modify('+1 day')));
        self::assertCount(1, $this->telegram);
        self::assertSame(1, $this->logs->count([]));
    }

    public function testAnInterestingOfferIsNotifiedSilentlyWhenConfiguredImmediate(): void
    {
        $job = $this->eligibleJob(); // priorité 81, date inconnue
        $policy = new NotificationPolicy(priorityThreshold: 90, interestingThreshold: 70);

        self::assertTrue($this->matchNotifier($policy)->notify($job, $this->now));
        $this->entityManager->flush();

        self::assertTrue($this->telegram[0]['disable_notification'], 'Une offre intéressante est notifiée sans bruit.');
        self::assertStringContainsString('Offre intéressante', $this->telegram[0]['text']);
        self::assertStringContainsString('Publication inconnue', $this->telegram[0]['text']);
        self::assertSame(NotificationLevel::Interesting, $this->logs->findAll()[0]->getLevel());
    }

    /** @return iterable<string, array{string}> */
    public static function nonImmediateModes(): iterable
    {
        yield 'récapitulatif' => ['digest'];
        yield 'désactivée' => ['off'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonImmediateModes')]
    public function testAnInterestingOfferIsNotNotifiedImmediatelyWhenConfiguredOtherwise(string $mode): void
    {
        $job = $this->eligibleJob();
        $policy = new NotificationPolicy(priorityThreshold: 90, interestingThreshold: 70, interestingMode: $mode);

        self::assertFalse($this->matchNotifier($policy)->notify($job, $this->now));

        self::assertSame([], $this->telegram);
        self::assertNull($job->getNotifiedAt());
        self::assertSame(0, $this->logs->count([]));
    }

    public function testASecondaryOfferIsNeverNotifiedImmediately(): void
    {
        $job = $this->eligibleJob(); // priorité 81
        $policy = new NotificationPolicy(priorityThreshold: 95, interestingThreshold: 90, secondaryThreshold: 60);

        self::assertFalse($this->matchNotifier($policy)->notify($job, $this->now));
        self::assertSame([], $this->telegram);
    }

    public function testAFreshButIncompatibleOfferIsNeverNotified(): void
    {
        $cobol = new JobRequirements(requiredSkills: ['COBOL', 'Fortran'], contractTypes: [ContractType::Cdi]);
        $job = $this->eligibleJob('Développeur COBOL', $this->now->modify('-10 minutes'), requirements: $cobol);

        self::assertFalse($this->matchNotifier(new NotificationPolicy(0, 0, 0))->notify($job, $this->now), 'Aucun profil ne la juge compatible : même avec des seuils à zéro.');
        self::assertSame([], $this->telegram);
    }

    public function testAnEliminatedOfferIsNeverNotified(): void
    {
        $this->writeProfile(self::CANDIDATE_WITH_CV, "search_profiles:\n  backend:\n    titles: [\"Développeur back-end\"]\n    excluded_keywords: [alternance]\n");
        $job = $this->persistAnalyzedJob('Développeur back-end PHP', $this->perfectRequirements(), $this->now, description: 'En alternance');
        $this->jobMatcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        self::assertFalse($this->matchNotifier(new NotificationPolicy(0, 0, 0))->notify($job, $this->now));
        self::assertSame([], $this->telegram);
    }

    public function testTheOfferIsNotNotifiedWhileItsAnalysisIsNotFinal(): void
    {
        $job = $this->eligibleJob(publishedAt: $this->now);
        $job->recordAnalysis(new AiAnalysisDto(['php'], ContractType::Cdi, false, false, 'non précisé', false, Seniority::Senior), 50, [], true, $this->now, $this->now->modify('+15 minutes'));
        $this->entityManager->flush();

        self::assertSame(AnalysisStatus::Fallback, $job->getAnalysisStatus());
        self::assertFalse($this->matchNotifier()->notify($job, $this->now));
        self::assertSame([], $this->telegram);
    }

    public function testAProbableDuplicateIsNeverNotified(): void
    {
        $job = $this->eligibleJob(publishedAt: $this->now);
        $job->markProbableDuplicateOf(1);
        $this->entityManager->flush();

        self::assertFalse($this->matchNotifier()->notify($job, $this->now));
    }

    public function testAnOfferNotifiedByTheLegacyMechanismIsNotNotifiedAgain(): void
    {
        $job = $this->eligibleJob(publishedAt: $this->now);
        $job->markAsNotified();
        $this->entityManager->flush();

        self::assertFalse($this->matchNotifier()->notify($job, $this->now));
        self::assertSame([], $this->telegram);
    }

    public function testATelegramFailureMarksNothingAndALaterAttemptSucceeds(): void
    {
        $job = $this->eligibleJob(publishedAt: $this->now);
        $notifier = $this->matchNotifier();
        $this->telegramFails = true;

        self::assertFalse($notifier->notify($job, $this->now));
        $this->entityManager->flush();
        self::assertNull($job->getNotifiedAt());
        self::assertSame(0, $this->logs->count([]));

        $this->telegramFails = false;
        self::assertTrue($notifier->notify($job, $this->now));
    }

    public function testAnHeuristicAnalysisCanOnlyBeInteresting(): void
    {
        $heuristic = new JobRequirements(
            mentionedSkills: ['PHP', 'Symfony'],
            remoteMode: RemotePreference::Remote,
            contractTypes: [ContractType::Cdi],
            source: ExtractionSource::Heuristic,
        );
        $job = $this->eligibleJob('Développeur back-end PHP', $this->now->modify('-1 hour'), requirements: $heuristic);
        $best = $this->matches->findBestEligible($job);
        self::assertGreaterThanOrEqual(80, $best?->getPriorityScore(), 'Le score justifierait une opportunité prioritaire.');

        self::assertTrue($this->matchNotifier()->notify($job, $this->now));
        $this->entityManager->flush();

        self::assertSame(NotificationLevel::Interesting, $this->logs->findAll()[0]->getLevel());
        self::assertTrue($this->telegram[0]['disable_notification']);
        self::assertStringContainsString('Analyse heuristique', $this->telegram[0]['text']);
    }

    public function testTheMessageNamesTheBestProfileAndItsCv(): void
    {
        $this->writeProfile(self::CANDIDATE_WITH_CV, self::BACKEND_ONLY . "\n  devops:\n    name: DevOps\n    titles: [\"Ingénieur DevOps\"]\n    primary_skills: [Kubernetes]\n    min_match_score: 60\n");
        $this->jobMatcher()->reset();
        $job = $this->eligibleJob(publishedAt: $this->now->modify('-1 hour'));

        $this->matchNotifier()->notify($job, $this->now);

        self::assertStringContainsString('Profil : Backend PHP / Symfony · CV : CV Backend PHP', $this->telegram[0]['text']);
        self::assertStringNotContainsString('DevOps', $this->telegram[0]['text']);
    }

    public function testItIsActiveOnlyWithACandidateAndAnActiveSearchProfile(): void
    {
        self::assertTrue($this->matchNotifier()->isActive());

        file_put_contents($this->profileDirectory . '/search_profiles.yaml', "search_profiles:\n  backend:\n    enabled: false\n    keywords: [php]\n");
        $this->jobMatcher()->reset();
        self::assertFalse($this->matchNotifier()->isActive());

        file_put_contents($this->profileDirectory . '/candidate.yaml', "skils: [PHP]\n");
        $this->jobMatcher()->reset();
        self::assertFalse($this->matchNotifier()->isActive(), 'Profil invalide : retour à l\'ancien mécanisme.');
    }

    // --- Rattrapage ------------------------------------------------------------------------------

    public function testCatchUpSendsMissedImmediateNotificationsOfRecentOffers(): void
    {
        $missed = $this->eligibleJob(publishedAt: $this->now->modify('-1 hour'));
        $this->eligibleJob(publishedAt: $this->now->modify('-1 hour'), url: 'https://jobs.example/old');
        $old = $this->entityManager->getRepository(\App\Entity\Job::class)->findOneBy(['url' => 'https://jobs.example/old']);
        $this->pushFirstSeenBack($old, 10);

        self::assertSame(1, $this->matchNotifier()->catchUp($this->now));
        $this->entityManager->flush();

        self::assertNotNull($missed->getNotifiedAt());
        self::assertNull($old->getNotifiedAt(), 'Une offre détectée il y a plus de 3 jours n\'est plus notifiée.');
        self::assertSame(0, $this->matchNotifier()->catchUp($this->now), 'Rien à rattraper une seconde fois.');
    }

    public function testCatchUpRespectsTheLimitAndDoesNotTouchDigestLevels(): void
    {
        foreach (range(1, 4) as $i) {
            $this->eligibleJob(publishedAt: $this->now->modify('-1 hour'), url: 'https://jobs.example/p' . $i);
        }

        $this->eligibleJob(url: 'https://jobs.example/digest');

        $policy = new NotificationPolicy(priorityThreshold: 90, interestingThreshold: 90, secondaryThreshold: 50);
        self::assertSame(4, $this->matchNotifier($policy)->catchUp($this->now, 10), 'Les offres prioritaires seules, pas celle du récapitulatif.');
        $this->entityManager->flush();

        foreach (range(1, 4) as $i) {
            $this->eligibleJob(publishedAt: $this->now->modify('-1 hour'), url: 'https://jobs.example/q' . $i);
        }

        self::assertSame(2, $this->matchNotifier($policy)->catchUp($this->now, 2));
    }
}
