<?php

declare(strict_types=1);

namespace App\Tests\Integration\Telegram;

use App\Entity\Job;
use Psr\Log\NullLogger;
use App\Telegram\OffsetStore;
use App\Telegram\TelegramBot;
use App\Tracking\JobUserState;
use App\Telegram\UpdateHandler;
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

final class InteractiveTelegramTest extends NotificationIntegrationTestCase
{
    private const int CHAT = 4242;

    /** @var list<array{method: string, body: array<string, mixed>}> Tous les appels sortants à l'API Bot */
    private array $api = [];
    /** @var list<array<string, mixed>> Réponses successives de getUpdates */
    private array $updateBatches = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeNotificationProfile();
        $this->api = [];
        $this->updateBatches = [];
    }

    private function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $name = basename($url);
            $body = json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR);
            $this->api[] = ['method' => $name, 'body' => $body];

            if ($name === 'getUpdates') {
                $batch = array_shift($this->updateBatches) ?? [];

                return new MockResponse(json_encode(['ok' => true, 'result' => $batch], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
    }

    private function handler(string $chatId = '4242', string $allowed = ''): UpdateHandler
    {
        $container = self::getContainer();
        $client = $this->client();

        return new UpdateHandler(
            new UpdateAuthorizer($chatId, $allowed),
            new TelegramBot($client, new NullLogger(), 'token'),
            new TelegramNotifier($client, new NullLogger(), 'token', $chatId, 1, 0),
            new TelegramMessageFormatter(),
            $this->jobs,
            $this->matches,
            $container->get(ApplicationRepository::class),
            $container->get(ApplicationManager::class),
            $this->entityManager,
            new NullLogger(),
        );
    }

    /** @return array<string, mixed> */
    private static function press(string $data, int $chat = self::CHAT, int $user = self::CHAT, int $messageId = 77): array
    {
        return ['update_id' => 1, 'callback_query' => ['id' => 'cb1', 'from' => ['id' => $user], 'data' => $data, 'message' => ['message_id' => $messageId, 'chat' => ['id' => $chat]]]];
    }

    /** @return array<string, mixed> */
    private static function say(string $text, int $chat = self::CHAT, int $user = self::CHAT): array
    {
        return ['update_id' => 1, 'message' => ['chat' => ['id' => $chat], 'from' => ['id' => $user], 'text' => $text]];
    }

    /** @return list<string> */
    private function methods(): array
    {
        return array_column($this->api, 'method');
    }

    /** @return array<string, mixed> */
    private function last(string $method): array
    {
        $matching = array_values(array_filter($this->api, static fn (array $call): bool => $call['method'] === $method));

        return $matching === [] ? [] : $matching[array_key_last($matching)]['body'];
    }

    // --- Autorisation -----------------------------------------------------------------------------

    public function testAnUnauthorizedSenderChangesNothingAndGetsNoAnswer(): void
    {
        $job = $this->eligibleJob();
        $handler = $this->handler();

        $handler->handle(self::press('t' . $job->getId(), user: 999), $this->now);
        $handler->handle(self::press('i' . $job->getId(), chat: 999, user: 999), $this->now);
        $handler->handle(self::say('/offres', user: 999), $this->now);
        $handler->handle(self::say('/candidatures', chat: 999, user: 999), $this->now);

        self::assertSame([], $this->api, 'Aucun appel sortant : le bot ne confirme pas son existence.');
        $this->entityManager->clear();
        self::assertSame(0, self::getContainer()->get(ApplicationRepository::class)->count([]));
        self::assertNull($this->entityManager->find(Job::class, $job->getId())->getUserState());
    }

    public function testAGroupChatIsRefusedWithoutAnAllowedUsersList(): void
    {
        $job = $this->eligibleJob();

        $this->handler('-100500')->handle(self::press('i' . $job->getId(), -100500, 42), $this->now);

        self::assertSame([], $this->api);
        self::assertNull($job->getUserState());
    }

    public function testAGroupChatAcceptsOnlyListedUsers(): void
    {
        $job = $this->eligibleJob();
        $handler = $this->handler('-100500', '42');

        $handler->handle(self::press('i' . $job->getId(), -100500, 43), $this->now);
        self::assertNull($job->getUserState());

        $handler->handle(self::press('i' . $job->getId(), -100500, 42), $this->now);
        self::assertSame(JobUserState::Ignored, $job->getUserState());
    }

    // --- Boutons ------------------------------------------------------------------------------------

    public function testTrackOpensADraftAndOffersToMarkItApplied(): void
    {
        $job = $this->eligibleJob();

        $this->handler()->handle(self::press('t' . $job->getId()), $this->now);

        $application = self::getContainer()->get(ApplicationRepository::class)->findOneByJob($job);
        self::assertSame(ApplicationStatus::Draft, $application?->getStatus());
        self::assertSame(['answerCallbackQuery', 'editMessageReplyMarkup'], $this->methods());
        self::assertSame('cb1', $this->last('answerCallbackQuery')['callback_query_id']);
        self::assertStringContainsString('rien n\'a été envoyé', $this->last('answerCallbackQuery')['text']);
        $edit = $this->last('editMessageReplyMarkup');
        self::assertSame(77, $edit['message_id']);
        self::assertSame(self::CHAT, $edit['chat_id']);
        self::assertSame('a' . $job->getId(), $edit['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function testTappingTwiceDoesNotDuplicateTheApplication(): void
    {
        $job = $this->eligibleJob();
        $handler = $this->handler();

        $handler->handle(self::press('t' . $job->getId()), $this->now);
        $this->entityManager->clear();
        $handler->handle(self::press('t' . $job->getId()), $this->now);

        self::assertSame(1, self::getContainer()->get(ApplicationRepository::class)->count([]));
    }

    public function testMarkAppliedRecordsTheStatusOnceAndLeavesNoButton(): void
    {
        $job = $this->eligibleJob();
        $handler = $this->handler();
        $handler->handle(self::press('t' . $job->getId()), $this->now);
        $this->entityManager->clear();

        $handler->handle(self::press('a' . $job->getId()), $this->now);
        $this->entityManager->clear();
        $handler->handle(self::press('a' . $job->getId()), $this->now);

        $application = self::getContainer()->get(ApplicationRepository::class)->findOneBy([]);
        self::assertSame(ApplicationStatus::Applied, $application->getStatus());
        self::assertNotNull($application->getAppliedAt());
        self::assertSame([], $this->last('editMessageReplyMarkup')['reply_markup']['inline_keyboard']);
        self::assertSame(1, self::getContainer()->get(\App\Repository\ApplicationStatusChangeRepository::class)->count(['toStatus' => 'applied']));
    }

    public function testMarkAppliedWithoutAnApplicationSaysSo(): void
    {
        $job = $this->eligibleJob();

        $this->handler()->handle(self::press('a' . $job->getId()), $this->now);

        self::assertStringContainsString('Aucune candidature', $this->last('answerCallbackQuery')['text']);
        self::assertSame(0, self::getContainer()->get(ApplicationRepository::class)->count([]));
    }

    public function testKeepIgnoreAndUndo(): void
    {
        $job = $this->eligibleJob();
        $handler = $this->handler();

        $handler->handle(self::press('s' . $job->getId()), $this->now);
        self::assertSame(JobUserState::Saved, $job->getUserState());

        $handler->handle(self::press('i' . $job->getId()), $this->now);
        self::assertSame(JobUserState::Ignored, $job->getUserState());
        self::assertSame('u' . $job->getId(), $this->last('editMessageReplyMarkup')['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $handler->handle(self::press('u' . $job->getId()), $this->now);
        self::assertNull($job->getUserState());
        self::assertCount(3, $this->last('editMessageReplyMarkup')['reply_markup']['inline_keyboard'][0]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCallbacks(): iterable
    {
        yield 'action inconnue' => ['x1'];
        yield 'offre inexistante' => ['t99999'];
        yield 'sans identifiant' => ['t'];
        yield 'injection' => ['t1; DROP TABLE job'];
        yield 'énorme' => ['t99999999999999999999'];
        yield 'vide' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCallbacks')]
    public function testMalformedOrUnknownCallbacksChangeNothing(string $data): void
    {
        $job = $this->eligibleJob();

        $this->handler()->handle(self::press($data), $this->now);

        self::assertSame(['answerCallbackQuery'], $this->methods());
        self::assertSame('Offre introuvable.', $this->last('answerCallbackQuery')['text']);
        self::assertNull($job->getUserState());
        self::assertSame(0, self::getContainer()->get(ApplicationRepository::class)->count([]));
    }

    public function testAnUpdateWithUnexpectedShapesIsIgnored(): void
    {
        $handler = $this->handler();

        foreach ([[], ['update_id' => 1], ['callback_query' => 'x'], ['message' => []], ['callback_query' => ['id' => 1, 'from' => ['id' => 'x'], 'message' => ['chat' => ['id' => '4242']]]], ['message' => ['chat' => ['id' => '4242'], 'from' => ['id' => '4242'], 'text' => '/offres']]] as $update) {
            $handler->handle($update, $this->now);
        }

        self::assertSame([], $this->api);
    }

    // --- Commandes ------------------------------------------------------------------------------------

    public function testOffersListsTheBestUndecidedOnesWithButtons(): void
    {
        $best = $this->eligibleJob('Meilleure offre', url: 'https://jobs.example/best');
        $ignored = $this->eligibleJob('Offre ignorée', url: 'https://jobs.example/ignored');
        $tracked = $this->eligibleJob('Offre suivie', url: 'https://jobs.example/tracked');
        $ignored->setUserState(JobUserState::Ignored, $this->now);
        self::getContainer()->get(ApplicationManager::class)->start($tracked, $this->now);
        $this->entityManager->flush();

        $this->handler()->handle(self::say('/offres'), $this->now);

        $texts = array_column(array_filter($this->api, static fn (array $call): bool => $call['method'] === 'sendMessage'), 'body');
        self::assertCount(1, $texts);
        self::assertStringContainsString('Meilleure offre', $texts[0]['text']);
        self::assertStringContainsString('Priorité', $texts[0]['text']);
        self::assertSame(['t' . $best->getId(), 's' . $best->getId(), 'i' . $best->getId()], array_column($texts[0]['reply_markup']['inline_keyboard'][0], 'callback_data'));
        self::assertSame((string) self::CHAT, (string) $texts[0]['chat_id']);
    }

    public function testOffersIsCappedAtFive(): void
    {
        foreach (range(1, 8) as $i) {
            $this->eligibleJob('Offre ' . $i, url: 'https://jobs.example/' . $i);
        }

        $this->handler()->handle(self::say('/offres'), $this->now);

        self::assertCount(5, array_filter($this->methods(), static fn (string $m): bool => $m === 'sendMessage'));
    }

    public function testOffersWithNothingToSortSaysSo(): void
    {
        $this->handler()->handle(self::say('/offres'), $this->now);

        self::assertStringContainsString('Aucune offre', $this->last('sendMessage')['text']);
    }

    public function testApplicationsShowsCountsAndOpenOnes(): void
    {
        $manager = self::getContainer()->get(ApplicationManager::class);
        $open = $manager->start($this->eligibleJob('Poste <ouvert>', url: 'https://jobs.example/1'), $this->now);
        $manager->changeStatus($open, ApplicationStatus::Applied, $this->now->modify('+1 hour'));
        $closed = $manager->start($this->eligibleJob('Poste fermé', url: 'https://jobs.example/2'), $this->now);
        $manager->changeStatus($closed, ApplicationStatus::Withdrawn, $this->now->modify('+1 hour'));
        $this->entityManager->flush();

        $this->handler()->handle(self::say('/candidatures'), $this->now);

        $text = $this->last('sendMessage')['text'];
        self::assertStringContainsString('Postulé : 1', $text);
        self::assertStringContainsString('Retirée : 1', $text);
        self::assertStringContainsString('Poste &lt;ouvert&gt;', $text);
        self::assertStringNotContainsString('Poste fermé', $text, 'Seules les candidatures en cours sont listées.');
    }

    public function testHelpAndUnknownTextAndBotSuffix(): void
    {
        $handler = $this->handler();

        $handler->handle(self::say('/aide'), $this->now);
        self::assertStringContainsString('/offres', $this->last('sendMessage')['text']);
        self::assertStringContainsString('ne contacte jamais un recruteur', $this->last('sendMessage')['text']);

        $this->api = [];
        $handler->handle(self::say('bonjour'), $this->now);
        self::assertStringContainsString('/candidatures', $this->last('sendMessage')['text']);

        $this->api = [];
        $this->eligibleJob();
        $handler->handle(self::say('/offres@jobscan_bot'), $this->now);
        self::assertStringContainsString('Priorité', $this->last('sendMessage')['text']);
    }

    // --- Notifications avec boutons --------------------------------------------------------------------

    public function testImmediateNotificationsCarryTheActionButtons(): void
    {
        $job = $this->eligibleJob(publishedAt: $this->now->modify('-1 hour'));
        $this->api = [];

        self::assertTrue($this->matchNotifier()->notify($job, $this->now));

        self::assertSame(['t' . $job->getId(), 's' . $job->getId(), 'i' . $job->getId()], array_column($this->telegram[0]['reply_markup']['inline_keyboard'][0], 'callback_data'));
    }

    // --- Commande d'écoute -----------------------------------------------------------------------------

    private function poller(string $offsetFile, string $chatId = '4242', ?MockHttpClient $client = null): CommandTester
    {
        $container = self::getContainer();
        $container->set(OffsetStore::class, new OffsetStore($offsetFile));
        $container->set(UpdateAuthorizer::class, new UpdateAuthorizer($chatId));
        $container->set(TelegramBot::class, new TelegramBot($client ?? $this->client(), new NullLogger(), 'token'));
        $container->set(UpdateHandler::class, $this->handler($chatId));

        return new CommandTester(new ConsoleApplication(self::$kernel)->find('app:telegram:poll'));
    }

    public function testThePollCommandProcessesUpdatesAndRemembersTheOffset(): void
    {
        $job = $this->eligibleJob();
        $file = sys_get_temp_dir() . '/jobscan-poll-' . bin2hex(random_bytes(4)) . '/offset';
        $this->updateBatches = [[
            ['update_id' => 10] + self::press('s' . $job->getId()),
            ['update_id' => 11] + self::say('/aide'),
        ]];

        $tester = $this->poller($file);

        self::assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));
        self::assertStringContainsString('2 mise(s) à jour traitée(s)', $tester->getDisplay());
        self::assertSame(12, new OffsetStore($file)->read());
        self::assertSame(0, $this->api[0]['body']['offset'], 'Premier appel sans offset enregistré.');
        $this->entityManager->clear();
        self::assertSame(JobUserState::Saved, $this->entityManager->find(Job::class, $job->getId())->getUserState());

        // Au redémarrage, on reprend après la dernière mise à jour traitée.
        $this->api = [];
        $tester->execute(['--once' => true]);
        self::assertSame(12, $this->api[0]['body']['offset']);

        unlink($file);
        rmdir(dirname($file));
    }

    public function testAnUpdateThatFailsDoesNotStopTheListeningNorGetReplayed(): void
    {
        $file = sys_get_temp_dir() . '/jobscan-poll-' . bin2hex(random_bytes(4)) . '/offset';
        $this->updateBatches = [[
            ['update_id' => 20, 'callback_query' => ['id' => 'x', 'from' => ['id' => self::CHAT], 'data' => 't1', 'message' => ['chat' => ['id' => self::CHAT], 'message_id' => 1]]],
            ['update_id' => 21] + self::say('/aide'),
        ]];

        $tester = $this->poller($file);

        self::assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));
        self::assertSame(22, new OffsetStore($file)->read());
        self::assertContains('sendMessage', $this->methods(), 'La mise à jour suivante est traitée.');

        unlink($file);
        rmdir(dirname($file));
    }

    public function testThePollCommandRefusesAGroupWithoutAllowedUsers(): void
    {
        $tester = $this->poller(sys_get_temp_dir() . '/jobscan-never', '-100500');

        self::assertSame(Command::FAILURE, $tester->execute(['--once' => true]));
        self::assertStringContainsString('TELEGRAM_ALLOWED_USER_IDS', $tester->getDisplay());
        self::assertSame([], $this->api);
    }

    public function testThePollCommandFailsWhenTelegramIsUnreachable(): void
    {
        $tester = $this->poller(sys_get_temp_dir() . '/jobscan-never', '4242', new MockHttpClient(static fn () => new MockResponse('{"ok":false}', ['http_code' => 401])));

        self::assertSame(Command::FAILURE, $tester->execute(['--once' => true]));
    }

    public function testThePollCommandValidatesItsDuration(): void
    {
        self::assertSame(Command::INVALID, $this->poller(sys_get_temp_dir() . '/jobscan-never')->execute(['--max-seconds' => '-3']));
    }
}
