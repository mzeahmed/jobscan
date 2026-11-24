<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Entity\Application;
use App\Tracking\ApplicationStatus;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<Application>
 */
class ApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Application::class);
    }

    public function save(Application $application): void
    {
        $this->getEntityManager()->persist($application);
    }

    public function findOneByJob(Job $job): ?Application
    {
        return $this->findOneBy(['job' => $job]);
    }

    /**
     * @param list<ApplicationStatus>|null $statuses Tous si `null`
     *
     * @return list<Application>
     */
    public function findByStatuses(?array $statuses = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('j')
            ->join('a.job', 'j')
            ->orderBy('a.statusChangedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($statuses !== null) {
            $qb->andWhere('a.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (ApplicationStatus $status): string => $status->value, $statuses));
        }

        /** @var list<Application> $applications */
        $applications = $qb->getQuery()->getResult();

        return $applications;
    }

    /** @return array<string, int> Nombre de candidatures par valeur de statut */
    public function countByStatus(): array
    {
        /** @var list<array{status: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.status AS status, COUNT(a.id) AS total')
            ->groupBy('a.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Candidatures par version de CV : envoyées, ayant reçu une réponse (entretien, test, proposition ou refus).
     * Les brouillons ne comptent pas : rien n'a été envoyé.
     *
     * @return array<string, array{sent: int, responded: int}>
     */
    public function statsByCv(): array
    {
        /** @var list<array{cv: string, sent: int|string, responded: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.cvVersionId AS cv, COUNT(a.id) AS sent, COUNT(a.respondedAt) AS responded')
            ->where('a.cvVersionId IS NOT NULL')
            ->andWhere('a.appliedAt IS NOT NULL')
            ->groupBy('a.cvVersionId')
            ->getQuery()
            ->getArrayResult();

        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['cv']] = ['sent' => (int) $row['sent'], 'responded' => (int) $row['responded']];
        }

        return $stats;
    }
}
