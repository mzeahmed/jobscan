<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\DTO\JobListingFilters;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Job>
 */
class JobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Job::class);
    }

    public function save(Job $job, bool $flush = true): void
    {
        $this->getEntityManager()->persist($job);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    public function clear(): void
    {
        $this->getEntityManager()->clear();
    }

    public function existsByUrlOrCanonicalUrl(string $url, string $canonicalUrl): bool
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.url = :url')
            ->orWhere('j.canonicalUrl = :canonicalUrl')
            ->setParameter('url', $url)
            ->setParameter('canonicalUrl', $canonicalUrl)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function existsByFingerprint(string $fingerprint): bool
    {
        return $this->count(['fingerprint' => $fingerprint]) > 0;
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function truncate(): int
    {
        $connection = $this->getEntityManager()->getConnection();

        return $connection->transactional(static function ($connection): int {
            $deleted = $connection->executeStatement('DELETE FROM job');
            if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
                $connection->executeStatement("DELETE FROM sqlite_sequence WHERE name = 'job'");
            }

            return $deleted;
        });
    }

    public function countToday(): int
    {
        $start = new \DateTimeImmutable('today midnight');
        $end = new \DateTimeImmutable('tomorrow midnight');

        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.createdAt >= :start')
            ->andWhere('j.createdAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countNotified(): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.notifiedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function averageScore(): float
    {
        return (float) $this->createQueryBuilder('j')
            ->select('COALESCE(AVG(j.score), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<string, int> */
    public function countBySource(): array
    {
        /** @var list<array{source: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('j')
            ->select('j.source AS source, COUNT(j.id) AS total')
            ->groupBy('j.source')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['source']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countByScoreRange(?int $minimum, ?int $maximum): int
    {
        $query = $this->createQueryBuilder('j')->select('COUNT(j.id)');
        if ($minimum !== null) {
            $query->andWhere('j.score >= :minimum')->setParameter('minimum', $minimum);
        }
        if ($maximum !== null) {
            $query->andWhere('j.score <= :maximum')->setParameter('maximum', $maximum);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * @return Job[]
     */
    public function findPaginated(int $page, int $limit): array
    {
        $offset = max(0, ($page - 1) * $limit);

        return $this->createQueryBuilder('j')
            ->orderBy('j.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(JobListingFilters $filters): int
    {
        return (int) $this->filteredQueryBuilder($filters)
            ->select('COUNT(j.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Job[] */
    public function findFilteredPaginated(JobListingFilters $filters, int $page, int $limit): array
    {
        $offset = max(0, ($page - 1) * $limit);
        $query = $this->filteredQueryBuilder($filters);

        if ($filters->sort === 'score') {
            $query->orderBy('j.score', 'DESC')->addOrderBy('j.createdAt', 'DESC');
        } else {
            $query->orderBy('j.createdAt', 'DESC');
        }

        return $query
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> */
    public function findSources(): array
    {
        /** @var list<string> $sources */
        $sources = $this->createQueryBuilder('j')
            ->select('DISTINCT j.source')
            ->orderBy('j.source', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return $sources;
    }

    private function filteredQueryBuilder(JobListingFilters $filters): QueryBuilder
    {
        $query = $this->createQueryBuilder('j');

        if ($filters->location !== null) {
            $query->andWhere('LOWER(j.location) LIKE LOWER(:location)')
                ->setParameter('location', '%' . $filters->location . '%');
        }
        if ($filters->remote !== null) {
            $query->andWhere('j.remote = :remote')->setParameter('remote', $filters->remote);
        }
        if ($filters->contractType !== null) {
            $query->andWhere('j.contractType = :contractType')->setParameter('contractType', $filters->contractType->value);
        }
        if ($filters->seniority !== null) {
            $query->andWhere('j.seniority = :seniority')->setParameter('seniority', $filters->seniority->value);
        }
        if ($filters->freelance !== null) {
            $query->andWhere('j.freelance = :freelance')->setParameter('freelance', $filters->freelance);
        }
        if ($filters->minimumScore !== null) {
            $query->andWhere('j.score >= :minimumScore')->setParameter('minimumScore', $filters->minimumScore);
        }
        if ($filters->source !== null) {
            $query->andWhere('j.source = :source')->setParameter('source', $filters->source);
        }
        if ($filters->ageInDays !== null) {
            $query->andWhere('j.createdAt >= :createdSince')
                ->setParameter('createdSince', new \DateTimeImmutable(sprintf('-%d days', $filters->ageInDays)));
        }
        if ($filters->notified !== null) {
            $query->andWhere($filters->notified ? 'j.notifiedAt IS NOT NULL' : 'j.notifiedAt IS NULL');
        }

        return $query;
    }

    /**
     * Compte les offres ingérées (`createdAt`) strictement avant la date donnée.
     */
    public function countCreatedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Supprime les offres ingérées (`createdAt`) strictement avant la date donnée.
     *
     * @return int Nombre d'offres supprimées
     */
    public function deleteCreatedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('j')
            ->delete()
            ->where('j.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * Retourne les offres destinées à l'export, triées par score décroissant
     * puis par date d'ingestion décroissante.
     *
     * @return Job[]
     */
    public function findForExport(?int $minScore = null): array
    {
        $query = $this->createQueryBuilder('j')
            ->orderBy('j.score', 'DESC')
            ->addOrderBy('j.createdAt', 'DESC');

        if ($minScore !== null) {
            $query->where('j.score >= :minScore')->setParameter('minScore', $minScore);
        }

        return $query->getQuery()->getResult();
    }

    //    /**
    //     * @return Job[] Returns an array of Job objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('j')
    //            ->andWhere('j.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('j.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Job
    //    {
    //        return $this->createQueryBuilder('j')
    //            ->andWhere('j.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
