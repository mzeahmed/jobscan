<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Notification\Notifier;
use App\Analysis\PendingAnalysisRunner;
use App\Notification\NotificationPolicy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Console\Application;

/**
 * Le chemin complet : analyse d'une offre → matching → notification, et la commande de récapitulatif.
 */
final class NotificationFlowTest extends NotificationIntegrationTestCase
{
    private const string ADVERT = 'Développeur back-end PHP Symfony en CDI, télétravail complet, 4 ans d\'expérience minimum. Docker apprécié.';

    public function testTheFacadeUsesTheMatchingWhenAProfileIsConfigured(): void
    {
        $this->writeNotificationProfile();
        $job = $this->eligibleJob(publishedAt: $this->now->modify('-1 hour'));

        self::assertTrue(self::getContainer()->get(Notifier::class)->notify($job));

        self::assertStringContainsString('Compatibilité', $this->telegram[0]['text']);
        self::assertStringNotContainsString('Nouvelle opportunité détectée', $this->telegram[0]['text']);
    }

    public function testTheFacadeKeepsTheLegacyBehaviourWithoutAProfile(): void
    {
        $job = $this->persistAnalyzedJob('Développeur PHP', $this->perfectRequirements(), $this->now);
        $job->setScore(80);
        $this->entityManager->flush();

        self::assertTrue(self::getContainer()->get(Notifier::class)->notify($job));

        self::assertStringContainsString('Nouvelle opportunité détectée', $this->telegram[0]['text']);
        self::assertStringContainsString('Score</b> : 80/100', $this->telegram[0]['text']);
    }

    public function testTheLegacyMessageEscapesApostrophesForTelegram(): void
    {
        $job = $this->persistAnalyzedJob('Ingénieur d\'affaires PHP', $this->perfectRequirements(), $this->now);
        $job->setScore(80);
        $this->entityManager->flush();

        self::getContainer()->get(Notifier::class)->notify($job);

        self::assertStringContainsString('Ingénieur d&#039;affaires PHP', $this->telegram[0]['text']);
        self::assertStringNotContainsString('&apos;', $this->telegram[0]['text']);
    }

    public function testAnAnalysedJobIsNotifiedOnceByTheRunner(): void
    {
        $this->writeNotificationProfile();
        $this->llm->default = json_encode([
            'required_skills' => ['PHP', 'Symfony'],
            'nice_skills' => ['Docker'],
            'min_years_experience' => 4,
            'remote_mode' => 'remote',
            'contract_types' => ['cdi'],
        ]);
        $this->entityManager->persist(Job::fromDTO(new JobDto('Développeur back-end PHP', 'https://jobs.example/p', self::ADVERT, 'test', $this->now->modify('-1 hour'), publicationDateSource: \App\DTO\PublicationDateSource::Api, publicationDateConfidence: \App\DTO\PublicationDateConfidence::High)));
        $this->entityManager->flush();
        $this->entityManager->clear();
        $runner = self::getContainer()->get(PendingAnalysisRunner::class);

        $summary = $runner->run(10, $this->now);

        self::assertSame(1, $summary->notified);
        self::assertCount(1, $this->telegram);
        self::assertStringContainsString('Priorité <b>', $this->telegram[0]['text']);
        self::assertFalse($this->telegram[0]['disable_notification']);

        self::assertSame(0, $runner->run(10, $this->now->modify('+1 hour'))->notified);
        self::assertCount(1, $this->telegram, 'Aucune notification en double.');
    }

    // --- Commande de récapitulatif -----------------------------------------------------------------

    public function testTheCommandExplainsWhenNoProfileIsConfigured(): void
    {
        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('score historique', $tester->getDisplay());
        self::assertSame([], $this->telegram);
    }

    public function testTheCommandSendsTheDigestAndCatchesUpMissedNotifications(): void
    {
        $this->writeNotificationProfile();
        $this->useSecondaryPolicy();
        $this->eligibleJob('Opportunité manquée', $this->now->modify('-1 hour'), 'https://jobs.example/missed');
        $this->eligibleJob('Offre secondaire', null, 'https://jobs.example/secondary');
        $this->entityManager->clear();

        $tester = $this->command();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/Notifications immédiates rattrapées\s+1/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Offres reprises dans le message\s+1/', $tester->getDisplay());
        self::assertCount(2, $this->telegram);
        self::assertStringContainsString('Opportunité manquée', $this->telegram[0]['text']);
        self::assertStringContainsString('Récapitulatif', $this->telegram[1]['text']);

        $again = $this->command();
        $again->execute([]);
        self::assertCount(2, $this->telegram, 'Rien de plus à la seconde exécution.');
        self::assertStringContainsString('Aucune offre à récapituler', $again->getDisplay());
    }

    public function testTheCommandDryRunPrintsThePreviewWithoutSendingAnything(): void
    {
        $this->writeNotificationProfile();
        $this->useSecondaryPolicy();
        $this->eligibleJob('Offre secondaire visible');
        $this->entityManager->clear();

        $tester = $this->command();
        $tester->execute(['--dry-run' => true, '--max-items' => '5']);

        self::assertStringContainsString('aperçu, non envoyé', $tester->getDisplay());
        self::assertStringContainsString('Offre secondaire visible', $tester->getDisplay());
        self::assertSame([], $this->telegram);
        self::assertSame(0, $this->logs->count([]));
    }

    public function testTheCommandReportsAFailedSendWithAFailureCode(): void
    {
        $this->writeNotificationProfile();
        $this->useSecondaryPolicy();
        $this->eligibleJob('Offre secondaire');
        $this->entityManager->clear();
        $this->telegramFails = true;

        $tester = $this->command();

        self::assertSame(Command::FAILURE, $tester->execute(['--no-catchup' => true]));
        self::assertStringContainsString('retenté au prochain lancement', $tester->getDisplay());
        self::assertSame(0, $this->logs->count([]));
    }

    public function testTheCommandRejectsAnInvalidMaxItems(): void
    {
        $this->writeNotificationProfile();

        self::assertSame(Command::INVALID, $this->command()->execute(['--max-items' => '0']));
        self::assertSame(Command::INVALID, $this->command()->execute(['--max-items' => 'abc']));
    }

    /** Offres parfaites mais à date inconnue (priorité 81) : « secondaires » pour le récapitulatif. */
    private function useSecondaryPolicy(): void
    {
        self::getContainer()->set(NotificationPolicy::class, new NotificationPolicy(priorityThreshold: 95, interestingThreshold: 90, secondaryThreshold: 60));
    }

    private function command(): CommandTester
    {
        return new CommandTester(new Application(self::$kernel)->find('app:notifications:digest'));
    }
}
