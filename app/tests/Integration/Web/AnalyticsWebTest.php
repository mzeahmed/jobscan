<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;

final class AnalyticsWebTest extends WebTestCaseBase
{
    public function testTheAnalyticsPageRequiresAuthentication(): void
    {
        $this->client->request('GET', '/analytics');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAnEmptyHistoryIsExplained(): void
    {
        $this->login();
        $this->client->request('GET', '/analytics');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Analytics');
        self::assertSelectorTextContains('.empty-state__title', 'Aucune candidature envoyée');
    }

    public function testThePageShowsTheFunnelAndEscapesSourceNames(): void
    {
        $this->login();
        $manager = self::getContainer()->get(ApplicationManager::class);
        foreach (range(1, 5) as $i) {
            $job = Job::fromDTO(new JobDto('Offre ' . $i, 'https://jobs.example/' . $i, 'D', '<script>alert(1)</script>'));
            $this->entityManager->persist($job);
            $this->entityManager->flush();
            $application = $manager->start($job, new \DateTimeImmutable('-30 days'));
            $manager->changeStatus($application, ApplicationStatus::Applied, new \DateTimeImmutable('-20 days'));
            if ($i <= 4) {
                $manager->changeStatus($application, ApplicationStatus::Interview, new \DateTimeImmutable('-15 days'));
            }
        }
        $this->entityManager->flush();

        $page = $this->client->request('GET', '/analytics');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/4\/5\s+80 %/', $page->text());
        self::assertCount(8, $page->filter('progress.meter[value="80"]'), 'Une barre par taux affiché : réponses et entretiens dans chacun des quatre tableaux de répartition.');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertMatchesRegularExpression('/5,0\s*jours/', $page->text(), 'Délai médian de 5 jours.');
    }

    public function testThePeriodSelectorAndAnInvalidPeriod(): void
    {
        $this->login();

        foreach (['30d', '90d', '365d', 'all', 'n-importe-quoi', '9999999d'] as $period) {
            $this->client->request('GET', '/analytics?since=' . $period);
            self::assertResponseIsSuccessful($period);
        }
        $page = $this->client->request('GET', '/analytics?since=n-importe-quoi');
        self::assertSame('90 jours', trim($page->filter('.chip--active')->text()), 'Une période inconnue retombe sur 90 jours.');
    }
}
