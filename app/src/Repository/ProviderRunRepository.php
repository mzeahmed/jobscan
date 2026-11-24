<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProviderRun;
use App\Monitoring\ProviderRunStatus;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<ProviderRun>
 */
class ProviderRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProviderRun::class);
    }

    public function save(ProviderRun $run): void
    {
        $this->getEntityManager()->persist($run);
        $this->getEntityManager()->flush();
    }

    /**
     * Dernière exécution connue de chaque provider.
     *
     * @return array<string, ProviderRun>
     */
    public function findLatestByProvider(): array
    {
        /** @var list<ProviderRun> $runs */
        $runs = $this->createQueryBuilder('r')
            ->where('r.startedAt = (SELECT MAX(r2.startedAt) FROM ' . ProviderRun::class . ' r2 WHERE r2.provider = r.provider)')
            ->orderBy('r.provider', 'ASC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($runs as $run) {
            $latest[$run->getProvider()] = $run;
        }

        return $latest;
    }

    /**
     * Date de la dernière exécution réussie de chaque provider.
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function findLastSuccessByProvider(): array
    {
        $statuses = array_map(
            static fn (ProviderRunStatus $status): string => $status->value,
            array_filter(ProviderRunStatus::cases(), static fn (ProviderRunStatus $status): bool => $status->isSuccessful()),
        );

        /** @var list<array{provider: string, lastSuccess: string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.provider AS provider, MAX(r.startedAt) AS lastSuccess')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', array_values($statuses))
            ->groupBy('r.provider')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['provider']] = new \DateTimeImmutable($row['lastSuccess']);
        }

        return $result;
    }
}
