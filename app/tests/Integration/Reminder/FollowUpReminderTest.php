<?php

declare(strict_types=1);

namespace App\Tests\Integration\Reminder;

use Psr\Log\NullLogger;
use App\Entity\Application;
use App\Telegram\TelegramBot;
use App\Telegram\UpdateHandler;
use App\Reminder\FollowUpReminder;
use App\Telegram\UpdateAuthorizer;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;
use App\Notification\TelegramNotifier;
use App\Repository\ApplicationRepository;
use App\Notification\TelegramMessageFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;
use App\Tests\Integration\Notification\NotificationIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;

final class FollowUpReminderTest extends NotificationIntegrationTestCase
{
    private ApplicationManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeNotificationProfile();
        $this->manager = self::getContainer()->get(ApplicationManager::class);
    }

    private function reminder(): FollowUpReminder
    {
        return self::getContainer()->get(FollowUpReminder::class);
    }

    private function applied(string $title, ?string $followUp, ApplicationStatus $status = ApplicationStatus::Applied, ?string $url = null): Application
    {
        $application = $this->manager->start($this->eligibleJob($title, url: $url ?? 'https://jobs.example/' . md5($title)), $this->now->modify('-30 days'));
        if ($status !== ApplicationStatus::Draft) {
            $this->manager->changeStatus($application, ApplicationStatus::Applied, $this->now->modify('-20 days'));
            if ($status !== ApplicationStatus::Applied) {
                $this->manager->changeStatus($application, $status, $this->now->modify('-10 days'));
            }
        }

        $application->setFollowUpAt($followUp === null ? null : $this->now->modify($followUp));
        $this->entityManager->flush();

        return $application;
    }

    public function testADueFollowUpIsRemindedOnceWithTheRightContent(): void
    {
        $application = $this->applied('Développeur PHP <b>senior</b>', '-3 days');
        $application->setRecruiterName('Camille Martin');
        $application->setRecruiterContact('camille@exemple.fr');
        $this->entityManager->flush();

        $result = $this->reminder()->send($this->now);
        $this->entityManager->flush();

        self::assertSame([1, 1, 0], [$result->due, $result->sent, $result->failed()]);
        self::assertCount(1, $this->telegram);
        $payload = $this->telegram[0];
        self::assertFalse($payload['disable_notification'], 'Un rappel est sonore.');
        $applied = $this->now->modify('-20 days')->format('d/m/Y');
        $planned = $this->now->modify('-3 days')->format('d/m/Y');
        foreach (['Relance à faire', 'Développeur PHP &lt;b&gt;senior&lt;/b&gt;', 'Statut : Postulé', sprintf('postulé le %s (il y a 20 j)', $applied), sprintf('Relance prévue le %s — en retard de 3 j', $planned), 'Voir l&#039;offre'] as $expected) {
            self::assertStringContainsString($expected, $payload['text'], $expected);
        }

        self::assertStringNotContainsString('Camille', $payload['text'], 'Les données d\'un tiers ne transitent pas par Telegram.');
        self::assertStringNotContainsString('exemple.fr', $payload['text']);
        self::assertSame(['r1', 'p1', 'q1'], array_column($payload['reply_markup']['inline_keyboard'][0], 'callback_data'));
        self::assertNotNull($application->getFollowUpRemindedAt());
    }

    public function testNoSecondReminderForTheSameDate(): void
    {
        $this->applied('Offre', '-1 day');

        $this->reminder()->send($this->now);
        $this->entityManager->flush();
        $second = $this->reminder()->send($this->now->modify('+1 day'));

        self::assertSame(0, $second->due);
        self::assertCount(1, $this->telegram);
    }

    public function testOnlyWhatIsDueAndOpenIsReminded(): void
    {
        $this->applied('À venir', '+2 days');
        $this->applied('Sans date', null);
        $this->applied('Brouillon', '-1 day', ApplicationStatus::Draft);
        $this->applied('Refusée', '-1 day', ApplicationStatus::Rejected);
        $this->applied('Retirée', '-1 day', ApplicationStatus::Withdrawn);
        $this->applied('Due', '-1 day');
        $this->applied('Entretien', '-2 days', ApplicationStatus::Interview);

        $result = $this->reminder()->send($this->now);

        self::assertSame(2, $result->due);
        $texts = implode("\n", array_column($this->telegram, 'text'));
        self::assertStringContainsString('Entretien', $texts);
        self::assertStringContainsString('Due', $texts);
        foreach (['À venir', 'Sans date', 'Brouillon', 'Refusée', 'Retirée'] as $ignored) {
            self::assertStringNotContainsString($ignored, $texts);
        }
    }

    public function testTheMostOverdueComeFirstAndTheLimitHolds(): void
    {
        $this->applied('Récente', '-1 day');
        $this->applied('Ancienne', '-9 days');
        $this->applied('Moyenne', '-4 days');

        $first = $this->reminder()->send($this->now, false, 2);
        $this->entityManager->flush();

        self::assertSame(2, $first->sent);
        self::assertStringContainsString('Ancienne', $this->telegram[0]['text']);
        self::assertStringContainsString('Moyenne', $this->telegram[1]['text']);

        $second = $this->reminder()->send($this->now, false, 2);
        self::assertSame(1, $second->sent, 'Le reste part au lancement suivant.');
        self::assertStringContainsString('Récente', $this->telegram[2]['text']);
    }

    public function testATelegramFailureMarksNothingSoItIsRetried(): void
    {
        $application = $this->applied('Offre', '-1 day');
        $this->telegramFails = true;

        $failed = $this->reminder()->send($this->now);
        $this->entityManager->flush();

        self::assertSame([1, 0, 1], [$failed->due, $failed->sent, $failed->failed()]);
        self::assertNull($application->getFollowUpRemindedAt());

        $this->telegramFails = false;
        self::assertSame(1, $this->reminder()->send($this->now)->sent);
    }

    public function testADryRunSendsAndMarksNothing(): void
    {
        $application = $this->applied('Offre visible', '-1 day');

        $result = $this->reminder()->send($this->now, true);

        self::assertSame([1, 0], [$result->due, $result->sent]);
        self::assertStringContainsString('Offre visible', $result->previews[0]);
        self::assertSame([], $this->telegram);
        self::assertNull($application->getFollowUpRemindedAt());
    }

    public function testChangingTheDateAfterAReminderSchedulesANewOne(): void
    {
        $application = $this->applied('Offre', '-5 days');
        $this->reminder()->send($this->now);
        $this->entityManager->flush();

        $application->setFollowUpAt($this->now->modify('-1 day'));
        $this->entityManager->flush();

        self::assertSame(1, $this->reminder()->send($this->now)->sent);
        self::assertCount(2, $this->telegram);
    }

    // --- Boutons ---------------------------------------------------------------------------------------

    private function handler(): UpdateHandler
    {
        $container = self::getContainer();
        $client = new MockHttpClient(fn (): MockResponse => new MockResponse('{"ok":true}', ['http_code' => 200]));

        return new UpdateHandler(
            new UpdateAuthorizer('4242'),
            new TelegramBot($client, new NullLogger(), 'token'),
            new TelegramNotifier($client, new NullLogger(), 'token', '4242', 1, 0),
            new TelegramMessageFormatter(),
            $this->jobs,
            $this->matches,
            $container->get(ApplicationRepository::class),
            $this->manager,
            $this->entityManager,
            new NullLogger(),
        );
    }

    /** @return array<string, mixed> */
    private static function press(string $data, int $user = 4242): array
    {
        return ['update_id' => 1, 'callback_query' => ['id' => 'cb', 'from' => ['id' => $user], 'data' => $data, 'message' => ['message_id' => 5, 'chat' => ['id' => 4242]]]];
    }

    public function testRelancedConsignsTheContactAndClearsTheFollowUp(): void
    {
        $application = $this->applied('Offre', '-1 day');
        $this->reminder()->send($this->now);
        $this->entityManager->flush();

        $this->handler()->handle(self::press('r' . $application->getId()), $this->now);

        self::assertNull($application->getFollowUpAt());
        self::assertSame($this->now->getTimestamp(), $application->getLastContactAt()?->getTimestamp());
        self::assertSame(0, $this->reminder()->send($this->now->modify('+30 days'))->due);
    }

    public function testSnoozeReschedulesAndRemindsAgainWhenTheNewDateComes(): void
    {
        $short = $this->applied('Courte', '-1 day');
        $long = $this->applied('Longue', '-1 day');
        $this->reminder()->send($this->now);
        $this->entityManager->flush();
        $this->telegram = [];

        $this->handler()->handle(self::press('p' . $short->getId()), $this->now);
        $this->handler()->handle(self::press('q' . $long->getId()), $this->now);

        self::assertSame($this->now->modify('+3 days')->format('Y-m-d'), $short->getFollowUpAt()?->format('Y-m-d'));
        self::assertSame($this->now->modify('+7 days')->format('Y-m-d'), $long->getFollowUpAt()?->format('Y-m-d'));
        self::assertSame(0, $this->reminder()->send($this->now->modify('+2 days'))->due);
        self::assertSame(1, $this->reminder()->send($this->now->modify('+3 days'))->sent);
        $this->entityManager->flush();
        self::assertStringContainsString('Courte', $this->telegram[0]['text']);

        $this->telegram = [];
        self::assertSame(1, $this->reminder()->send($this->now->modify('+8 days'))->sent, 'La longue revient 7 jours plus tard.');
        self::assertStringContainsString('Longue', $this->telegram[0]['text']);
    }

    public function testReminderButtonsRefuseUnknownApplicationsAndStrangers(): void
    {
        $application = $this->applied('Offre', '-1 day');
        $handler = $this->handler();

        $handler->handle(self::press('r' . $application->getId(), 999), $this->now);
        self::assertNotNull($application->getFollowUpAt(), 'Un inconnu ne change rien.');

        $handler->handle(self::press('r99999'), $this->now);
        $handler->handle(self::press('r'), $this->now);
        self::assertNotNull($application->getFollowUpAt());
    }

    // --- Commande --------------------------------------------------------------------------------------

    private function command(): CommandTester
    {
        return new CommandTester(new ConsoleApplication(self::$kernel)->find('app:reminders:send'));
    }

    public function testTheCommandSendsOnceThenReportsNothingToDo(): void
    {
        $this->applied('Offre', '-1 day', url: 'https://jobs.example/cmd');

        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('1 rappel(s) envoyé(s)', $tester->getDisplay());

        $again = $this->command();
        self::assertSame(Command::SUCCESS, $again->execute([]));
        self::assertStringContainsString('Aucune relance à rappeler', $again->getDisplay());
        self::assertCount(1, $this->telegram);
    }

    public function testTheCommandDryRunPrintsThePreview(): void
    {
        $this->applied('Offre à relancer', '-1 day');

        $tester = $this->command();
        $tester->execute(['--dry-run' => true]);

        self::assertStringContainsString('Offre à relancer', $tester->getDisplay());
        self::assertStringContainsString('aperçu, non envoyé', $tester->getDisplay());
        self::assertSame([], $this->telegram);
    }

    public function testTheCommandFailsWhenTelegramDoes(): void
    {
        $this->applied('Offre', '-1 day');
        $this->telegramFails = true;

        $tester = $this->command();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(Command::INVALID, $this->command()->execute(['--limit' => '0']));
    }

    public function testTheListMarksDueFollowUps(): void
    {
        $this->applied('Offre due', '-1 day');
        $list = new CommandTester(new ConsoleApplication(self::$kernel)->find('app:applications:list'));

        $list->execute([]);

        self::assertStringContainsString('⏰', $list->getDisplay());
    }
}
