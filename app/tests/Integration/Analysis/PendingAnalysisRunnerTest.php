<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analysis;

use App\DTO\JobDto;
use App\Entity\Job;
use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use App\DTO\AnalysisStatus;
use App\Repository\JobRepository;
use Doctrine\ORM\Tools\SchemaTool;
use App\AI\Extraction\JobRequirements;
use App\AI\Extraction\ExtractionSource;
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
        $this->llm->default = '{"required_skills":["Symfony","PHP"],"contract_types":["freelance"],"remote_mode":"remote","seniority":"senior"}';
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

    public function testPersistsTheStructuredRequirementsWithTheirSchemaVersion(): void
    {
        $this->llm->default = json_encode([
            'required_skills' => ['Symfony', 'PHP'],
            'nice_skills' => ['Docker'],
            'min_years_experience' => 3,
            'remote_mode' => 'remote',
            'contract_types' => ['freelance'],
        ]);
        $this->persistPending(1, 'Symfony PHP requis, Docker apprécié, 3 ans d\'expérience, mission freelance en télétravail');

        $this->runner()->run(10);

        $job = $this->repository->findOneBy([]);
        $requirements = $job->getRequirements();
        self::assertNotNull($requirements);
        self::assertSame(JobRequirements::SCHEMA_VERSION, $job->getExtractionVersion());
        self::assertSame(['Symfony', 'PHP'], $requirements->requiredSkills);
        self::assertSame(['Docker'], $requirements->niceSkills);
        self::assertSame(3, $requirements->minYearsExperience);
        self::assertSame(ExtractionSource::Llm, $requirements->source);
        self::assertSame(['symfony', 'php', 'docker'], $job->getStack(), 'La vue historique reste alimentée.');
    }

    public function testAHeuristicAnalysisStoresMinimalRequirementsFlaggedAsSuch(): void
    {
        $this->llm->default = null;
        $this->persistPending(1);

        $this->runner()->run(10);

        $requirements = $this->repository->findOneBy([])->getRequirements();
        self::assertSame(ExtractionSource::Heuristic, $requirements?->source);
        self::assertSame([], $requirements->requiredSkills);
    }

    public function testReextractionRefreshesOutdatedJobsWithoutNotifyingOrCountingAnAttempt(): void
    {
        $this->persistDoneWithoutRequirements('Développeur Symfony PHP');
        $this->llm->default = '{"required_skills":["Symfony","PHP"],"contract_types":["freelance"],"remote_mode":"remote","seniority":"senior"}';

        $summary = $this->runner()->reextractOutdated(10);

        self::assertSame(1, $summary->analyzedByAi);
        self::assertSame(0, $summary->notified);
        self::assertCount(0, $this->telegramMessages, 'Une ré-extraction ne notifie jamais.');
        $job = $this->repository->findOneBy([]);
        self::assertSame(AnalysisStatus::Done, $job->getAnalysisStatus());
        self::assertSame(1, $job->getAnalysisAttempts(), 'Pas une reprise : le compteur ne bouge pas.');
        self::assertSame(['Symfony', 'PHP'], $job->getRequirements()?->requiredSkills);
        self::assertSame(JobRequirements::SCHEMA_VERSION, $job->getExtractionVersion());
        self::assertGreaterThan(0, $job->getScore());
    }

    public function testReextractionNeverReplacesAnLlmAnalysisWithTheHeuristic(): void
    {
        $this->persistDoneWithoutRequirements('Développeur Symfony PHP');
        $before = $this->repository->findOneBy([]);
        $scoreBefore = $before->getScore();
        $stackBefore = $before->getStack();
        $this->entityManager->clear();
        $this->llm->default = null;

        $summary = $this->runner()->reextractOutdated(10);

        self::assertSame(0, $summary->analyzedByAi);
        self::assertSame(1, $summary->skipped);
        $job = $this->repository->findOneBy([]);
        self::assertSame(AnalysisStatus::Done, $job->getAnalysisStatus());
        self::assertNull($job->getRequirements(), 'Inchangé : sera retenté plus tard.');
        self::assertNull($job->getExtractionVersion());
        self::assertSame($scoreBefore, $job->getScore());
        self::assertSame($stackBefore, $job->getStack());
    }

    public function testReextractionOnlyTouchesOutdatedJobs(): void
    {
        $this->persistDoneWithoutRequirements('Développeur Symfony PHP');
        $this->llm->default = '{"required_skills":["PHP"]}';
        $this->runner()->reextractOutdated(10);
        $callsAfterFirst = $this->llm->calls;

        $summary = $this->runner()->reextractOutdated(10);

        self::assertSame(0, $summary->analyzedByAi + $summary->skipped + $summary->failed);
        self::assertSame($callsAfterFirst, $this->llm->calls, 'Une offre à jour n\'est pas ré-analysée.');
    }

    public function testReextractionIgnoresJobsThatAreNotDone(): void
    {
        $this->llm->default = null;
        $this->persistPending(1);
        $this->runner()->run(10);
        $this->llm->default = '{"required_skills":["PHP"]}';
        $callsBefore = $this->llm->calls;

        self::assertSame(0, $this->runner()->reextractOutdated(10)->analyzedByAi);
        self::assertSame($callsBefore, $this->llm->calls, 'Les analyses heuristiques passent par la reprise ordinaire.');
    }

    private function persistDoneWithoutRequirements(string $description): void
    {
        $job = Job::fromDTO(new JobDto('Développeur Symfony', 'https://jobs.example/old', $description, 'test'));
        $job->recordAnalysis(
            new AiAnalysisDto(['php'], ContractType::Unknown, false, false, 'non précisé', false, Seniority::Unknown),
            20,
            [],
            false,
            new \DateTimeImmutable('-3 days'),
            null,
        );
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function runner(): PendingAnalysisRunner
    {
        return self::getContainer()->get(PendingAnalysisRunner::class);
    }

    private function persistPending(int $count, string $description = 'Symfony PHP freelance remote mission'): void
    {
        for ($index = 1; $index <= $count; ++$index) {
            $job = \App\Entity\Job::fromDTO(new JobDto(
                'Développeur Symfony ' . $index,
                'https://jobs.example/' . $index,
                $description,
                'test',
            ));
            $this->entityManager->persist($job);
            $this->entityManager->flush();
            usleep(1000);
        }

        $this->entityManager->clear();
    }
}
