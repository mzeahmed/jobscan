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

    /** @return list<ApplicationStatusChange> Dans l'ordre où les statuts se sont succédé */
    public function findHistory(Application $application): array
    {
        /** @var list<ApplicationStatusChange> $history */
        $history = $this->createQueryBuilder('c')
            ->where('c.application = :application')
            ->setParameter('application', $application)
            // Ordre d'enregistrement, pas de date : une candidature saisie après coup (envoyée il y a une semaine, brouillon créé
            // aujourd'hui) a des dates qui ne suivent pas la séquence réelle des statuts.
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $history;
    }
}
