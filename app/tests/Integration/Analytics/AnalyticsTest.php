<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Entity\Application;
use App\Analytics\AnalyticsService;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationChannel;
use App\Tracking\ApplicationManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use App\Tests\Integration\Notification\NotificationIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;

final class AnalyticsTest extends NotificationIntegrationTestCase
{
    private ApplicationManager $manager;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeNotificationProfile();
        $this->manager = self::getContainer()->get(ApplicationManager::class);
    }

    private function service(): AnalyticsService
    {
        return self::getContainer()->get(AnalyticsService::class);
    }

    /**
     * @param list<array{ApplicationStatus, int}> $path Statuts suivants et jours après l'envoi
     */
    private function application(string $source = 'test', ?string $cv = 'backend-php', int $sentDaysAgo = 10, array $path = [], ?ApplicationChannel $channel = ApplicationChannel::Email): Application
    {
        $job = Job::fromDTO(new JobDto('Offre ' . ++$this->sequence, 'https://jobs.example/a' . $this->sequence, 'D', $source));
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $application = $this->manager->start($job, $this->now->modify('-60 days'), 'backend', $cv);
        $application->setChannel($channel);
        $sent = $this->now->modify(sprintf('-%d days', $sentDaysAgo));
        $this->manager->changeStatus($application, ApplicationStatus::Applied, $sent);
        foreach ($path as [$status, $days]) {
            $this->manager->changeStatus($application, $status, $sent->modify(sprintf('+%d days', $days)));
        }
        $this->entityManager->flush();

        return $application;
    }

    public function testTheFunnelIsReadFromTheHistoryNotOnlyFromTheCurrentStatus(): void
    {
        $this->application(path: [[ApplicationStatus::Interview, 2], [ApplicationStatus::Offer, 5], [ApplicationStatus::Accepted, 6]]);
        $this->application(path: [[ApplicationStatus::Interview, 4], [ApplicationStatus::Rejected, 8]]);
        $this->application(path: [[ApplicationStatus::TechnicalTest, 6], [ApplicationStatus::Rejected, 9]]);
        $this->application(path: [[ApplicationStatus::Rejected, 2]]);
        $this->application(path: [[ApplicationStatus::NoResponse, 14]]);
        $this->application();
        $this->application(sentDaysAgo: 3, path: [[ApplicationStatus::Withdrawn, 1]]);

        $f = $this->service()->report(null, $this->now)->funnel;

        self::assertSame(7, $f->sent);
        self::assertSame(4, $f->responded, 'Entretien, test ou refus : « sans réponse » et « retirée » n\'en sont pas.');
        self::assertSame(3, $f->interviews, 'Une candidature refusée après un entretien l\'a bien atteint.');
        self::assertSame(1, $f->offers);
        self::assertSame(1, $f->accepted);
        self::assertSame(3, $f->rejected, 'Statut actuel : après un entretien, après un test, ou directement ; les deux premières comptent aussi dans « entretiens ».');
        self::assertSame(1, $f->noResponse);
    }

    public function testTheMedianDelayToTheFirstResponse(): void
    {
        $this->application(path: [[ApplicationStatus::Interview, 2], [ApplicationStatus::Offer, 20]]);
        $this->application(path: [[ApplicationStatus::Interview, 4]]);
        $this->application(path: [[ApplicationStatus::Rejected, 9]]);

        self::assertSame(4.0, $this->service()->report(null, $this->now)->funnel->medianDaysToResponse, 'La première réponse seulement : 2, 4 et 9 jours.');

        $this->application(path: [[ApplicationStatus::Interview, 10]]);
        self::assertSame(6.5, $this->service()->report(null, $this->now)->funnel->medianDaysToResponse, 'Médiane de 2, 4, 9 et 10.');
    }

    public function testNoDataGivesZerosAndNoMedian(): void
    {
        $report = $this->service()->report(null, $this->now);

        self::assertSame(0, $report->funnel->sent);
        self::assertNull($report->funnel->medianDaysToResponse);
        self::assertSame([], $report->breakdowns['cv']);
        self::assertSame([], $report->sourcing);
    }

    public function testDraftsAreNotCountedAsSent(): void
    {
        $job = Job::fromDTO(new JobDto('Brouillon', 'https://jobs.example/draft', 'D', 'test'));
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $this->manager->start($job, $this->now);
        $this->entityManager->flush();
        $this->application();

        self::assertSame(1, $this->service()->report(null, $this->now)->funnel->sent);
    }

    public function testTheWindowKeepsOnlyRecentSendings(): void
    {
        $this->application(sentDaysAgo: 100, path: [[ApplicationStatus::Interview, 3]]);
        $this->application(sentDaysAgo: 10);

        self::assertSame(2, $this->service()->report(null, $this->now)->funnel->sent);
        $recent = $this->service()->report($this->now->modify('-30 days'), $this->now);
        self::assertSame(1, $recent->funnel->sent);
        self::assertSame(0, $recent->funnel->responded);
    }

    public function testBreakdownByCvIsSortedAndUsesTheCvLabel(): void
    {
        foreach (range(1, 6) as $i) {
            $this->application(cv: 'backend-php', path: $i <= 3 ? [[ApplicationStatus::Interview, 2]] : []);
        }
        $this->application(cv: 'backend-php', path: [[ApplicationStatus::Offer, 3]]);

        $rows = $this->service()->report(null, $this->now)->breakdowns['cv'];

        self::assertCount(1, $rows);
        self::assertSame('CV Backend PHP', $rows[0]->label);
        self::assertSame([7, 4, 3, 1], [$rows[0]->sent, $rows[0]->responded, $rows[0]->interviews, $rows[0]->offers]);
    }

    public function testBreakdownsBySourceProfileAndChannel(): void
    {
        $this->application(source: 'remoteok', channel: ApplicationChannel::Linkedin, path: [[ApplicationStatus::Interview, 2]]);
        $this->application(source: 'remoteok', channel: ApplicationChannel::Linkedin);
        $this->application(source: 'rss', channel: null);

        $breakdowns = $this->service()->report(null, $this->now)->breakdowns;

        self::assertSame(['remoteok', 'rss'], array_map(static fn ($r): string => $r->label, $breakdowns['source']));
        self::assertSame([2, 1], array_map(static fn ($r): int => $r->sent, $breakdowns['source']));
        self::assertSame(['Backend PHP / Symfony'], array_map(static fn ($r): string => $r->label, $breakdowns['profile']), 'Nom du profil, pas son identifiant.');
        self::assertSame(['linkedin', 'non renseigné'], array_map(static fn ($r): string => $r->label, $breakdowns['channel']));
    }

    public function testSourcingFollowsAnOfferFromCollectionToApplicationAndIgnoresDuplicates(): void
    {
        $compatible = $this->eligibleJob('Compatible', url: 'https://jobs.example/compatible');
        $notified = $this->eligibleJob('Notifiée', url: 'https://jobs.example/notified');
        $notified->markAsNotified();
        $duplicate = $this->eligibleJob('Doublon', url: 'https://jobs.example/duplicate');
        $duplicate->markProbableDuplicateOf((int) $compatible->getId());
        $other = Job::fromDTO(new JobDto('Autre source', 'https://jobs.example/other', 'D', 'rss'));
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $application = $this->manager->start($notified, $this->now);
        $this->manager->changeStatus($application, ApplicationStatus::Applied, $this->now);
        $this->entityManager->flush();

        $rows = $this->service()->report(null, $this->now)->sourcing;

        $byName = array_column(array_map(static fn ($r): array => (array) $r, $rows), null, 'source');
        self::assertSame(['test' => [2, 2, 1, 1, 1], 'rss' => [1, 0, 0, 0, 0]], array_map(static fn (array $r): array => [$r['collected'], $r['compatible'], $r['notified'], $r['tracked'], $r['applied']], $byName), 'Le doublon n\'est pas compté.');
        self::assertSame('test', $rows[0]->source, 'Les sources les plus fournies d\'abord.');
    }

    // --- Commande ----------------------------------------------------------------------------------

    private function command(): CommandTester
    {
        return new CommandTester(new ConsoleApplication(self::$kernel)->find('app:analytics:report'));
    }

    public function testTheCommandPrintsTheReportWithThePrudenceNote(): void
    {
        foreach (range(1, 5) as $i) {
            $this->application(path: [[ApplicationStatus::Interview, 2]]);
        }
        $this->application(cv: null);

        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();

        self::assertStringContainsString('Envoyées', $display);
        self::assertStringContainsString('5/6 (83 %)', $display, 'Assez de candidatures : un pourcentage.');
        self::assertStringContainsString('CV Backend PHP', $display);
        self::assertStringContainsString('5/5 (100 %)', $display);
        self::assertStringContainsString('pas des preuves', $display);
    }

    public function testTheCommandNeverShowsAPercentageOnATinySample(): void
    {
        $this->application(path: [[ApplicationStatus::Interview, 2]]);

        $tester = $this->command();
        $tester->execute([]);

        self::assertStringContainsString('1/1', $tester->getDisplay());
        self::assertStringNotContainsString('100 %', $tester->getDisplay());
    }

    public function testTheCommandJsonOutputIsValidAndNullsRatesOnSmallSamples(): void
    {
        $this->application(path: [[ApplicationStatus::Interview, 2]]);

        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json', '--since' => 'all']));
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $data['funnel']['sent']);
        self::assertNull($data['funnel']['response_rate']);
        self::assertSame(5, $data['min_sample_for_rates']);
        self::assertNull($data['since']);
        self::assertSame('CV Backend PHP', $data['breakdowns']['cv'][0]['label']);
    }

    public function testTheCommandRefusesInvalidOptionsAndExplainsAnEmptyHistory(): void
    {
        self::assertSame(Command::INVALID, $this->command()->execute(['--since' => 'hier']));
        self::assertSame(Command::INVALID, $this->command()->execute(['--format' => 'xml']));

        $tester = $this->command();
        $tester->execute([]);
        self::assertStringContainsString('Aucune candidature envoyée', $tester->getDisplay());
    }
}
