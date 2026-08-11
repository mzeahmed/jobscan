<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
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
