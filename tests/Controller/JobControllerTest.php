<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Job;
use App\Repository\JobRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JobControllerTest extends WebTestCase
{
    public function test_index_renders_the_jobs_list(): void
    {
        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository
            ->expects($this->once())
            ->method('countAll')
            ->willReturn(2);

        $jobRepository
            ->expects($this->once())
            ->method('findPaginated')
            ->with(1, 10)
            ->willReturn([
                $this->createJob('Senior Symfony Developer', 'https://example.com/jobs/symfony', 'Build and maintain local jobscan features.', 'RemoteOK', 94, '2026-05-20 09:30:00'),
                $this->createJob('PHP Engineer', 'https://example.com/jobs/php', 'Own the backend pipeline and improve the command line experience.', 'We Work Remotely', 88, '2026-05-19 08:00:00'),
            ]);

        $client = self::createClient();
        self::getContainer()->set(JobRepository::class, $jobRepository);
        $client->request('GET', '/job');

        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('Offres d\'emploi', $content);
        self::assertStringContainsString('2 offres au total dans la base locale.', $content);
        self::assertStringContainsString('Senior Symfony Developer', $content);
        self::assertStringContainsString('PHP Engineer', $content);

        $seniorPosition = strpos($content, 'Senior Symfony Developer');
        $phpPosition = strpos($content, 'PHP Engineer');

        self::assertIsInt($seniorPosition);
        self::assertIsInt($phpPosition);
        self::assertGreaterThan($seniorPosition, $phpPosition);
    }

    public function test_index_shows_an_empty_state_when_no_jobs_are_available(): void
    {
        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository
            ->expects($this->once())
            ->method('countAll')
            ->willReturn(0);

        $jobRepository
            ->expects($this->once())
            ->method('findPaginated')
            ->with(1, 10)
            ->willReturn([]);

        $client = self::createClient();
        self::getContainer()->set(JobRepository::class, $jobRepository);
        $client->request('GET', '/job');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucune offre disponible pour le moment.', $client->getResponse()->getContent());
    }

    public function test_index_clamps_requested_page_to_the_last_available_page(): void
    {
        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository
            ->expects($this->once())
            ->method('countAll')
            ->willReturn(21);

        $jobRepository
            ->expects($this->once())
            ->method('findPaginated')
            ->with(3, 10)
            ->willReturn([
                $this->createJob('Last Page Job', 'https://example.com/jobs/last-page', 'Job rendered on the last page.', 'LinkedIn', 72, '2026-05-01 09:00:00'),
            ]);

        $client = self::createClient();
        self::getContainer()->set(JobRepository::class, $jobRepository);
        $client->request('GET', '/job?page=999');

        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('Page 3 sur 3', $content);
        self::assertStringContainsString('Page precedente', $content);
        self::assertStringNotContainsString('Page suivante', $content);
    }

    private function createJob(string $title, string $url, string $description, string $source, int $score, string $createdAt): Job
    {
        $job = new Job;

        $job->setTitle($title);
        $job->setUrl($url);
        $job->setDescription($description);
        $job->setSource($source);
        $job->setScore($score);
        $job->setCreatedAt(new \DateTimeImmutable($createdAt));

        return $job;
    }
}
