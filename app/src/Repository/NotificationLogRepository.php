<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Entity\NotificationLog;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<NotificationLog>
 */
class NotificationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationLog::class);
    }

    public function save(NotificationLog $log): void
    {
        $this->getEntityManager()->persist($log);
    }

    /** Une offre a-t-elle déjà été notifiée, immédiatement ou dans un récapitulatif ? */
    public function existsForJob(Job $job): bool
    {
        return $this->count(['job' => $job]) > 0;
    }

    /** @return array<string, int> */
    public function countByKind(): array
    {
        /** @var list<array{kind: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.kind AS kind, COUNT(l.id) AS total')
            ->groupBy('l.kind')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['kind']] = (int) $row['total'];
        }

        return $counts;
    }
}
