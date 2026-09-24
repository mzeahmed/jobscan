<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\DTO\JobDto;
use App\Entity\Job;
use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use App\DTO\JobListingFilters;
use App\Repository\JobRepository;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class JobRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private JobRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(JobRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testFiltersAndCountsWithTheSameCriteria(): void
    {
        $matching = $this->job('Paris remote freelance', 'feed-a', 'Paris, France', 90, true, true, ContractType::Freelance, Seniority::Senior);
        $this->job('Paris CDI', 'feed-a', 'Paris, France', 90, true, false, ContractType::Cdi, Seniority::Senior);
        $this->job('Remote Berlin', 'feed-b', 'Berlin, Germany', 90, true, true, ContractType::Freelance, Seniority::Senior);
        $this->entityManager->flush();

        $filters = new JobListingFilters(
            location: 'paris',
            remote: true,
            contractType: ContractType::Freelance,
            seniority: Seniority::Senior,
            freelance: true,
            minimumScore: 75,
            source: 'feed-a',
            notified: false,
        );

        self::assertSame(1, $this->repository->countFiltered($filters));
        self::assertSame([$matching->getId()], array_map(static fn (Job $job): ?int => $job->getId(), $this->repository->findFilteredPaginated($filters, 1, 10)));
        self::assertSame(['feed-a', 'feed-b'], $this->repository->findSources());
    }

    public function testSortsByScoreAndPaginates(): void
    {
        $lowerScore = $this->job('Lower', 'feed', 'Paris', 50, false, false, ContractType::Unknown, Seniority::Unknown);
        $higherScore = $this->job('Higher', 'feed', 'Paris', 80, false, false, ContractType::Unknown, Seniority::Unknown);
        $this->entityManager->flush();

        $jobs = $this->repository->findFilteredPaginated(new JobListingFilters(sort: 'score'), 1, 1);

        self::assertSame([$higherScore->getId()], array_map(static fn (Job $job): ?int => $job->getId(), $jobs));
        self::assertNotSame($lowerScore->getId(), $jobs[0]->getId());
    }

    private function job(
        string $title,
        string $source,
        string $location,
        int $score,
        bool $remote,
        bool $freelance,
        ContractType $contractType,
        Seniority $seniority,
    ): Job {
        $job = Job::fromDTO(new JobDto($title, 'https://jobs.example/' . urlencode($title), 'Description', $source, company: 'Acme', location: $location));
        $job->setScore($score);
        $job->setAnalysis(new AiAnalysisDto([], $contractType, $freelance, $remote, 'non précisé', false, $seniority), []);
        $this->entityManager->persist($job);

        return $job;
    }
}
