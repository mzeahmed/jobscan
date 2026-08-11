<?php

declare(strict_types=1);

namespace App\Tests\Processor;

use App\AI\Provider\LLMClientInterface;
use App\DTO\JobDto;
use App\Processor\JobProcessingStatus;
use App\Processor\JobProcessor;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class JobProcessorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private JobRepository $repository;
    private MutableLlmClient $llm;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->llm = new MutableLlmClient();
        $container->set('llm.client', $this->llm);
        $container->get('cache.app')->clear();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(JobRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        unset($this->entityManager, $this->repository);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{JobDto, JobProcessingStatus}>
     */
    public static function rejectedJobs(): iterable
    {
        yield 'hors sujet' => [
            new JobDto('Comptable', 'https://jobs.example/filtered', 'Comptabilité', 'test'),
            JobProcessingStatus::Filtered,
        ];
        yield 'trop ancienne' => [
            new JobDto(
                'Développeur PHP',
                'https://jobs.example/old',
                'PHP',
                'test',
                new \DateTimeImmutable('-60 days'),
            ),
            JobProcessingStatus::TooOld,
        ];
        yield 'pré-score insuffisant' => [
            new JobDto('Backend developer', 'https://jobs.example/low', 'Backend', 'test'),
            JobProcessingStatus::LowPrescore,
        ];
    }

    #[DataProvider('rejectedJobs')]
    public function testRejectsJobsBeforePersistence(JobDto $dto, JobProcessingStatus $expectedStatus): void
    {
        $result = $this->processor()->process($dto);

        self::assertSame($expectedStatus, $result->status);
        self::assertSame(0, $this->repository->countAll());
    }

    public function testPersistsAndDeduplicatesJobsWithinTheSameBatch(): void
    {
        $first = $this->job(1, 'https://jobs.example/1?utm_source=test');
        $sameCanonicalUrl = $this->job(2, 'https://jobs.example/1');
        $sameFingerprint = new JobDto(
            'Développeur PHP 1',
            'https://jobs.example/different',
            'PHP remote',
            'test',
            company: 'Acme 1',
            location: 'Paris',
        );

        $results = $this->processor()->processBatch([$first, $sameCanonicalUrl, $sameFingerprint]);

        self::assertSame([
            JobProcessingStatus::Saved,
            JobProcessingStatus::Duplicate,
            JobProcessingStatus::Duplicate,
        ], array_map(static fn ($result) => $result->status, $results));
        self::assertSame(1, $this->repository->countAll());
        self::assertFalse($results[0]->notified, 'Une offre sous le seuil ne doit pas être notifiée.');
    }

    public function testDryRunDoesNotPersistAnything(): void
    {
        $results = $this->processor()->processBatch([$this->job(1), $this->job(2)], true);

        self::assertSame([JobProcessingStatus::DryRun, JobProcessingStatus::DryRun], array_map(
            static fn ($result) => $result->status,
            $results,
        ));
        self::assertSame(0, $this->repository->countAll());
    }

    public function testUsesFallbackWhenLlmIsUnavailable(): void
    {
        $this->llm->responses = [null];

        $result = $this->processor()->process($this->job(1));

        self::assertSame(JobProcessingStatus::Saved, $result->status);
        self::assertTrue($result->usedFallback);
        self::assertSame(1, $this->repository->countAll());
    }

    public function testFlushesAFullCollectionInConfiguredBatches(): void
    {
        $listener = new class {
            public int $flushes = 0;

            public function postFlush(): void
            {
                ++$this->flushes;
            }
        };
        $this->entityManager->getEventManager()->addEventListener([Events::postFlush], $listener);

        $jobs = [];
        for ($index = 1; $index <= 41; ++$index) {
            $jobs[] = $this->job($index);
        }

        $results = $this->processor()->processBatch($jobs);

        self::assertCount(41, $results);
        self::assertSame(41, $this->repository->countAll());
        self::assertSame(3, $listener->flushes);
    }

    public function testContinuesAfterAnIndividualProcessingFailure(): void
    {
        $this->llm->responses = [
            new \RuntimeException('LLM failure'),
            MutableLlmClient::SUCCESS_RESPONSE,
        ];

        $results = $this->processor()->processBatch([$this->job(1), $this->job(2)]);

        self::assertSame(JobProcessingStatus::Failed, $results[0]->status);
        self::assertSame(JobProcessingStatus::Saved, $results[1]->status);
        self::assertSame(1, $this->repository->countAll());
    }

    private function processor(): JobProcessor
    {
        return self::getContainer()->get(JobProcessor::class);
    }

    private function job(int $index, ?string $url = null): JobDto
    {
        return new JobDto(
            'Développeur PHP ' . $index,
            $url ?? 'https://jobs.example/' . $index,
            'PHP remote',
            'test',
            company: 'Acme ' . $index,
            location: 'Paris',
        );
    }

}

final class MutableLlmClient implements LLMClientInterface
{
    public const string SUCCESS_RESPONSE = '{"stack":["php"],"contract_type":"unknown","freelance":false,"remote":false,"budget":"non précisé","seniority":"unknown"}';

    /** @var list<string|null|\Throwable> */
    public array $responses = [];

    public function analyze(string $systemPrompt, string $userText): ?string
    {
        $response = $this->responses === [] ? self::SUCCESS_RESPONSE : array_shift($this->responses);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
