<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Entity\JobMatch;
use App\Matching\MatchVerdict;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<JobMatch>
 */
class JobMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobMatch::class);
    }

    public function save(JobMatch $match): void
    {
        $this->getEntityManager()->persist($match);
    }

    public function findOneByJobAndProfile(Job $job, string $searchProfileId): ?JobMatch
    {
        return $this->findOneBy(['job' => $job, 'searchProfileId' => $searchProfileId]);
    }

    /**
     * Résultats d'une offre, le plus prioritaire d'abord.
     *
     * @return list<JobMatch>
     */
    public function findByJob(Job $job): array
    {
        return array_values($this->createQueryBuilder('m')
            ->where('m.job = :job')
            ->setParameter('job', $job)
            ->orderBy('m.priorityScore', 'DESC')
            ->addOrderBy('m.matchScore', 'DESC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Offres les plus prioritaires, avec leur profil de recherche le plus pertinent.
     *
     * Sans `$searchProfileId`, chaque offre n'apparaît que pour son meilleur profil (priorité maximale
     * parmi les profils où elle est compatible).
     *
     * @return list<JobMatch>
     */
    public function findTop(int $limit, ?string $searchProfileId = null, bool $eligibleOnly = true): array
    {
        $query = $this->createQueryBuilder('m')
            ->addSelect('j')
            ->join('m.job', 'j')
            ->orderBy('m.priorityScore', 'DESC')
            ->addOrderBy('m.matchScore', 'DESC')
            ->addOrderBy('j.firstSeenAt', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($eligibleOnly) {
            $query->andWhere('m.verdict = :eligible')->setParameter('eligible', MatchVerdict::Eligible->value);
        }

        if ($searchProfileId !== null) {
            $query->andWhere('m.searchProfileId = :profile')->setParameter('profile', $searchProfileId);
        } else {
            $query->andWhere(sprintf(
                'm.priorityScore = (SELECT MAX(m2.priorityScore) FROM %s m2 WHERE m2.job = m.job%s)',
                JobMatch::class,
                $eligibleOnly ? ' AND m2.verdict = :eligible' : '',
            ));
        }

        return array_values($query->getQuery()->getResult());
    }

    /**
     * Résultats par lot, pour rafraîchir les priorités. L'offre est chargée avec le résultat.
     *
     * @return list<JobMatch>
     */
    public function findBatchAfterId(int $afterId, int $limit): array
    {
        return array_values($this->createQueryBuilder('m')
            ->addSelect('j')
            ->join('m.job', 'j')
            ->where('m.id > :after')
            ->setParameter('after', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult());
    }

    /**
     * Supprime les résultats des profils de recherche qui ne sont plus actifs ou n'existent plus.
     * Données dérivées : elles se recalculent si le profil revient.
     *
     * @param list<string> $activeProfileIds
     */
    public function deleteExceptProfiles(array $activeProfileIds): int
    {
        $query = $this->createQueryBuilder('m')->delete();
        if ($activeProfileIds !== []) {
            $query->where('m.searchProfileId NOT IN (:ids)')->setParameter('ids', $activeProfileIds);
        }

        return (int) $query->getQuery()->execute();
    }

    /** @return array<string, int> */
    public function countByVerdict(): array
    {
        /** @var list<array{verdict: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.verdict AS verdict, COUNT(m.id) AS total')
            ->groupBy('m.verdict')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['verdict']] = (int) $row['total'];
        }

        return $counts;
    }
}
