<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Entity\Job;
use App\DTO\Seniority;
use Psr\Log\NullLogger;
use App\DTO\ContractType;
use App\Matching\JobMatcher;
use App\Profile\ProfileRegistry;
use App\Notification\MatchNotifier;
use App\Notification\DigestNotifier;
use App\AI\Extraction\JobRequirements;
use App\Notification\TelegramNotifier;
use App\Profile\Model\RemotePreference;
use App\Notification\NotificationPolicy;
use App\Repository\NotificationLogRepository;
use App\Notification\TelegramMessageFormatter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use App\Tests\Integration\Matching\MatchingIntegrationTestCase;

/**
 * Notifications sur une base SQLite de test, avec un Telegram simulé qui consigne chaque message.
 */
abstract class NotificationIntegrationTestCase extends MatchingIntegrationTestCase
{
    protected const string CANDIDATE_WITH_CV = <<<'YAML'
        skills: [PHP, Symfony, Docker]
        experiences:
          - {company: Exemple SAS, title: Développeur, start: "2015-01", skills: [PHP, Symfony]}
        remote_modes: [remote, hybrid]
        contracts: [cdi]
        cvs:
          - {id: backend-php, label: CV Backend PHP}
        YAML;

    protected const string BACKEND_ONLY = <<<'YAML'
        search_profiles:
          backend:
            name: Backend PHP / Symfony
            titles: ["Développeur back-end"]
            primary_skills: [PHP]
            min_match_score: 60
            cv: backend-php
        YAML;

    /** @var list<array<string, mixed>> */
    protected array $telegram = [];
    protected bool $telegramFails = false;
    protected NotificationLogRepository $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->telegram = [];
        $this->telegramFails = false;
        $this->logs = self::getContainer()->get(NotificationLogRepository::class);
        self::getContainer()->set('http_client', $this->telegramClient());
    }

    protected function writeNotificationProfile(): void
    {
        $this->writeProfile(self::CANDIDATE_WITH_CV, self::BACKEND_ONLY);
    }

    /** Une offre parfaitement compatible : MatchScore 100, donc priorité 100 (fraîche), 81 (date inconnue) ou 76 (ancienne). */
    protected function eligibleJob(
        string $title = 'Développeur back-end PHP',
        ?\DateTimeImmutable $publishedAt = null,
        string $url = 'https://jobs.example/1',
        ?JobRequirements $requirements = null,
    ): Job {
        $job = $this->persistAnalyzedJob($title, $requirements ?? $this->perfectRequirements(), $publishedAt, url: $url);
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        return $job;
    }

    protected function perfectRequirements(): JobRequirements
    {
        return new JobRequirements(
            requiredSkills: ['PHP', 'Symfony'],
            niceSkills: ['Docker'],
            seniority: Seniority::Senior,
            minYearsExperience: 4,
            remoteMode: RemotePreference::Remote,
            contractTypes: [ContractType::Cdi],
        );
    }

    protected function matchNotifier(?NotificationPolicy $policy = null): MatchNotifier
    {
        $container = self::getContainer();

        return new MatchNotifier(
            $container->get(ProfileRegistry::class),
            $this->matches,
            $this->logs,
            $this->telegramNotifier(),
            new TelegramMessageFormatter(),
            $policy ?? new NotificationPolicy(),
            new NullLogger(),
        );
    }

    protected function digestNotifier(?NotificationPolicy $policy = null): DigestNotifier
    {
        $container = self::getContainer();

        return new DigestNotifier(
            $container->get(ProfileRegistry::class),
            $this->matches,
            $this->logs,
            $this->telegramNotifier(),
            new TelegramMessageFormatter(),
            $policy ?? new NotificationPolicy(),
            new NullLogger(),
        );
    }

    protected function jobMatcher(): JobMatcher
    {
        return $this->matcher();
    }

    private function telegramNotifier(): TelegramNotifier
    {
        return new TelegramNotifier($this->telegramClient(), new NullLogger(), 'token', 'chat', 1, 0);
    }

    private function telegramClient(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if ($this->telegramFails) {
                return new MockResponse('{"ok":false}', ['http_code' => 500]);
            }

            $this->telegram[] = json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR);

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });
    }

    protected function pushFirstSeenBack(Job $job, int $days): void
    {
        new \ReflectionProperty(Job::class, 'firstSeenAt')->setValue($job, $this->now->modify(sprintf('-%d days', $days)));
        $this->entityManager->flush();
    }
}
