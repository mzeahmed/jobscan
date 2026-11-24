<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Application;
use App\Entity\ApplicationStatusChange;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<ApplicationStatusChange>
 */
class ApplicationStatusChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApplicationStatusChange::class);
    }

    public function save(ApplicationStatusChange $change): void
    {
        $this->getEntityManager()->persist($change);
    }

    /** @return list<ApplicationStatusChange> Du plus ancien au plus récent */
    public function findHistory(Application $application): array
    {
        /** @var list<ApplicationStatusChange> $history */
        $history = $this->createQueryBuilder('c')
            ->where('c.application = :application')
            ->setParameter('application', $application)
            ->orderBy('c.changedAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $history;
    }
}
