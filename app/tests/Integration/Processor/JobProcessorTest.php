<?php

declare(strict_types=1);

namespace App\Tests\Integration\Processor;

use App\DTO\JobDto;
use App\Scoring\Scoring;
use Doctrine\ORM\Events;
use App\DTO\AnalysisStatus;
use Psr\Log\LoggerInterface;
use App\Analysis\JobAnalyzer;
use App\Processor\JobIdentity;
use App\Processor\JobProcessor;
use App\Repository\JobRepository;
use App\DTO\PublicationDateSource;
use Doctrine\ORM\Tools\SchemaTool;
use App\DTO\PublicationDateConfidence;
use App\Processor\JobProcessingStatus;
use App\Repository\JobSourceRepository;
use App\Tests\Support\MutableLlmClient;
use Doctrine\ORM\EntityManagerInterface;
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

    public function testPersistsBeforeAnalysisAndDeduplicatesJobsWithinTheSameBatch(): void
    {
        $first = $this->job(1, 'https://jobs.example/1?utm_source=test');
        $sameCanonicalUrl = $this->job(2, 'https://jobs.example/1');

        $results = $this->processor()->processBatch([$first, $sameCanonicalUrl]);

        self::assertSame([JobProcessingStatus::Saved, JobProcessingStatus::Duplicate], array_map(
            static fn ($result) => $result->status,
            $results,
        ));
        self::assertSame(1, $this->repository->countAll());
        self::assertSame(0, $this->llm->calls, "L'ingestion ne doit jamais appeler le LLM.");

        $job = $this->repository->findOneBy([]);
        self::assertSame(AnalysisStatus::Pending, $job->getAnalysisStatus());
        self::assertSame(0, $job->getScore());
        self::assertFalse($results[0]->notified);
    }

    public function testDryRunDoesNotPersistAnythingButPreviewsScores(): void
    {
        $results = $this->processor()->processBatch([$this->job(1), $this->job(2)], true);

        self::assertSame([JobProcessingStatus::DryRun, JobProcessingStatus::DryRun], array_map(
            static fn ($result) => $result->status,
            $results,
        ));
        self::assertNotNull($results[0]->score);
        self::assertSame(0, $this->repository->countAll());
    }

    public function testKeepsCollectingWhenTheLlmIsDown(): void
    {
        $this->llm->default = new \RuntimeException('LLM down');

        $results = $this->processor()->processBatch([$this->job(1), $this->job(2)]);

        self::assertSame([JobProcessingStatus::Saved, JobProcessingStatus::Saved], array_map(
            static fn ($result) => $result->status,
            $results,
        ));
        self::assertSame(2, $this->repository->countAll());
        self::assertSame(0, $this->llm->calls);
    }

    public function testFlushesAFullCollectionInConfiguredBatches(): void
    {
        $listener = new class () {
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

    public function testExcludesConfiguredPlatformDomainsBeforePersistence(): void
    {
        $results = $this->processorWithExcludedDomains(['linkedin.com', 'indeed.com'])->processBatch([
            $this->job(1, 'https://www.linkedin.com/jobs/view/1'),
            $this->job(2, 'https://fr.indeed.com/viewjob?jk=2'),
            $this->job(3, 'https://notlinkedin.com/jobs/3'),
        ]);

        self::assertSame([
            JobProcessingStatus::Filtered,
            JobProcessingStatus::Filtered,
            JobProcessingStatus::Saved,
        ], array_map(static fn ($result) => $result->status, $results));
        self::assertSame(1, $this->repository->countAll());
    }

    public function testStoresTheSourceWithItsDateAndConfidence(): void
    {
        $published = new \DateTimeImmutable('-2 days');
        $this->processor()->process(new JobDto(
            'Développeur PHP',
            'https://jobs.example/a?utm_source=x',
            'PHP remote',
            'remoteok',
            $published,
            'Acme',
            'Paris',
            externalId: '42',
            publicationDateSource: PublicationDateSource::Api,
            publicationDateConfidence: PublicationDateConfidence::High,
        ));

        $job = $this->repository->findOneBy([]);
        self::assertSame(PublicationDateConfidence::High, $job->getPublicationDateConfidence());
        self::assertSame(PublicationDateSource::Api, $job->getPublicationDateSource());
        self::assertEquals($job->getCreatedAt(), $job->getFirstSeenAt());

        $sources = $this->sources()->findByJob((int) $job->getId());
        self::assertCount(1, $sources);
        self::assertSame('remoteok', $sources[0]->getProvider());
        self::assertSame('42', $sources[0]->getExternalId());
        self::assertSame('https://jobs.example/a', $sources[0]->getCanonicalUrl());
    }

    public function testACertainDuplicateFromAnotherProviderAddsASourceAndKeepsTheMostReliableDate(): void
    {
        $guessed = new \DateTimeImmutable('-1 day');
        $official = new \DateTimeImmutable('-3 days');
        $processor = $this->processor();

        $processor->process(new JobDto(
            'Développeur PHP',
            'https://jobs.example/offer',
            'PHP remote',
            'searxng',
            $guessed,
            'Acme',
            'Paris',
            publicationDateSource: PublicationDateSource::Text,
            publicationDateConfidence: PublicationDateConfidence::Low,
        ));
        $result = $processor->process(new JobDto(
            'Développeur PHP',
            'https://jobs.example/offer?utm_campaign=mail',
            'PHP remote',
            'rss',
            $official,
            'Acme',
            'Paris',
            publicationDateSource: PublicationDateSource::Feed,
            publicationDateConfidence: PublicationDateConfidence::Medium,
        ));

        self::assertSame(JobProcessingStatus::Duplicate, $result->status);
        self::assertSame(1, $this->repository->countAll());

        $job = $this->repository->findOneBy([]);
        self::assertSame($official->format('Y-m-d'), $job->getPublishedAt()?->format('Y-m-d'));
        self::assertSame(PublicationDateConfidence::Medium, $job->getPublicationDateConfidence());
        self::assertSame('searxng', $job->getSource(), 'La source initiale reste celle de la première détection.');
        self::assertCount(2, $this->sources()->findByJob((int) $job->getId()));
    }

    public function testARepublicationDoesNotResetTheFirstKnownPublicationDate(): void
    {
        $processor = $this->processor();
        $original = new \DateTimeImmutable('-5 days');
        $republished = new \DateTimeImmutable('-1 day');
        $dto = static fn (\DateTimeImmutable $date): JobDto => new JobDto(
            'Développeur PHP',
            'https://jobs.example/offer',
            'PHP remote',
            'rss',
            $date,
            publicationDateSource: PublicationDateSource::Feed,
            publicationDateConfidence: PublicationDateConfidence::Medium,
        );

        $processor->process($dto($original));
        $processor->process($dto($republished));

        $job = $this->repository->findOneBy([]);
        self::assertSame($original->format('Y-m-d'), $job->getPublishedAt()?->format('Y-m-d'));
        self::assertGreaterThanOrEqual($job->getFirstSeenAt(), $job->getLastSeenAt());
        self::assertCount(1, $this->sources()->findByJob((int) $job->getId()));
    }

    public function testDetectsADuplicateFromTheExternalIdentifierEvenWhenTheUrlChanges(): void
    {
        $processor = $this->processor();
        $dto = static fn (string $url): JobDto => new JobDto('Développeur PHP', $url, 'PHP remote', 'remoteok', externalId: '777');

        $processor->process($dto('https://remoteok.test/jobs/777-a'));
        $result = $processor->process($dto('https://remoteok.test/jobs/777-b'));

        self::assertSame(JobProcessingStatus::Duplicate, $result->status);
        self::assertSame(1, $this->repository->countAll());
        self::assertCount(1, $this->sources()->findAll(), 'Une seule observation par provider et identifiant externe.');
    }

    public function testAProbableDuplicateIsKeptAndFlaggedNeverMerged(): void
    {
        $processor = $this->processor();
        $dto = static fn (string $url, string $source): JobDto => new JobDto(
            'Développeur PHP',
            $url,
            'PHP remote',
            $source,
            company: 'Acme',
            location: 'Paris',
        );

        $processor->process($dto('https://a.example/1', 'rss'));
        $result = $processor->process($dto('https://b.example/other', 'searxng'));

        self::assertSame(JobProcessingStatus::Saved, $result->status);
        self::assertSame(2, $this->repository->countAll());

        $jobs = $this->repository->findBy([], ['id' => 'ASC']);
        self::assertNull($jobs[0]->getProbableDuplicateOfId());
        self::assertSame($jobs[0]->getId(), $jobs[1]->getProbableDuplicateOfId());
    }

    private function sources(): JobSourceRepository
    {
        return self::getContainer()->get(JobSourceRepository::class);
    }

    private function processor(): JobProcessor
    {
        return self::getContainer()->get(JobProcessor::class);
    }

    /** @param list<string> $domains */
    private function processorWithExcludedDomains(array $domains): JobProcessor
    {
        $container = self::getContainer();

        return new JobProcessor(
            $this->repository,
            $container->get(JobSourceRepository::class),
            $container->get(Scoring::class),
            $container->get(JobAnalyzer::class),
            $container->get(JobIdentity::class),
            $container->get(LoggerInterface::class),
            ['php', 'symfony', 'wordpress', 'backend', 'fullstack', 'api'],
            $domains,
        );
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
