<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analysis;

use App\DTO\JobDto;
use App\DTO\AnalysisStatus;
use App\Repository\JobRepository;
use Doctrine\ORM\Tools\SchemaTool;
use App\Analysis\PendingAnalysisRunner;
use App\Tests\Support\MutableLlmClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PendingAnalysisRunnerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private JobRepository $repository;
    private MutableLlmClient $llm;
    /** @var list<string> */
    private array $telegramMessages = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->llm = new MutableLlmClient();
        $container->set('llm.client', $this->llm);
        $container->get('cache.app')->clear();

        $this->telegramMessages = [];
        $container->set('http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->telegramMessages[] = (string) json_decode((string) $options['body'], true)['text'];

            return new MockResponse('{"ok":true}');
        }));

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(JobRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnalyzesPendingJobsAndNotifiesTheStrongOnes(): void
    {
        $this->llm->default = '{"stack":["symfony","php"],"contract_type":"freelance","freelance":true,"remote":true,"budget":"600€/j","seniority":"senior"}';
        $this->persistPending(1);

        $summary = $this->runner()->run(10);

        self::assertSame(1, $summary->analyzedByAi);
        self::assertSame(1, $summary->notified);
        $job = $this->repository->findOneBy([]);
        self::assertSame(AnalysisStatus::Done, $job->getAnalysisStatus());
        self::assertGreaterThanOrEqual(60, $job->getScore());
        self::assertNotNull($job->getAnalyzedAt());
        self::assertNotNull($job->getNotifiedAt());
        self::assertCount(1, $this->telegramMessages);
    }

    public function testAnUnavailableLlmLeavesJobsToBeRetriedWithoutNotifying(): void
    {
        $this->llm->default = null;
        $this->persistPending(2);

        $summary = $this->runner()->run(10);

        self::assertSame(2, $summary->analyzedByFallback);
        self::assertSame(0, $summary->notified, 'Une analyse heuristique encore retentable ne notifie pas.');
        foreach ($this->repository->findAll() as $job) {
            self::assertSame(AnalysisStatus::Fallback, $job->getAnalysisStatus());
            self::assertNotNull($job->getNextAnalysisAttemptAt());
            self::assertNull($job->getNotifiedAt());
        }
    }

    public function testAHeuristicAnalysisIsReplacedByTheLlmOnceItIsBackAndTheRetryIsDue(): void
    {
        $this->llm->default = null;
        $this->persistPending(1);
        $now = new \DateTimeImmutable();
        $this->runner()->run(10, $now);

        $notDue = $this->runner()->run(10, $now->modify('+5 minutes'));
        self::assertSame(0, $notDue->total(), "La prochaine tentative n'est pas échue.");

        $this->llm->default = MutableLlmClient::SUCCESS_RESPONSE;
        $later = $this->runner()->run(10, $now->modify('+20 minutes'));

        self::assertSame(1, $later->analyzedByAi);
        $job = $this->repository->findOneBy([]);
        self::assertSame(AnalysisStatus::Done, $job->getAnalysisStatus());
        self::assertSame(2, $job->getAnalysisAttempts());
        self::assertNull($job->getNextAnalysisAttemptAt());
    }

    public function testAnExceptionMarksOnlyThatJobAsFailedAndTheRunGoesOn(): void
    {
        $this->llm->responses = [new \RuntimeException('boom'), MutableLlmClient::SUCCESS_RESPONSE];
        $this->persistPending(2);

        $summary = $this->runner()->run(10);

        self::assertSame(1, $summary->failed);
        self::assertSame(1, $summary->analyzedByAi);
        $statuses = array_map(static fn ($job) => $job->getAnalysisStatus(), $this->repository->findAll());
        self::assertContains(AnalysisStatus::Failed, $statuses);
        self::assertContains(AnalysisStatus::Done, $statuses);
    }

    public function testStopsRetryingOnceTheAttemptsAreExhausted(): void
    {
        $this->llm->default = null;
        $this->persistPending(1);
        $now = new \DateTimeImmutable();

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $this->runner()->run(10, $now->modify(sprintf('+%d days', $attempt)));
        }

        $job = $this->repository->findOneBy([]);
        self::assertSame(5, $job->getAnalysisAttempts(), 'Jamais plus que le maximum configuré.');
        self::assertNull($job->getNextAnalysisAttemptAt());
        self::assertTrue($job->isAnalysisFinal());
    }

    public function testRespectsTheBatchLimitAndAnalyzesTheNewestFirst(): void
    {
        $this->persistPending(3);

        $summary = $this->runner()->run(2);

        self::assertSame(2, $summary->total());
        $pending = $this->repository->findBy(['analysisStatus' => AnalysisStatus::Pending->value]);
        self::assertCount(1, $pending);
    }

    private function runner(): PendingAnalysisRunner
    {
        return self::getContainer()->get(PendingAnalysisRunner::class);
    }

    private function persistPending(int $count): void
    {
        for ($index = 1; $index <= $count; ++$index) {
            $job = \App\Entity\Job::fromDTO(new JobDto(
                'Développeur Symfony ' . $index,
                'https://jobs.example/' . $index,
                'Symfony PHP freelance remote mission',
                'test',
            ));
            $this->entityManager->persist($job);
            $this->entityManager->flush();
            usleep(1000);
        }
        $this->entityManager->clear();
    }
}
