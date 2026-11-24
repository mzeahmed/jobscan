<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Entity\JobMatch;
use App\Entity\Application;
use App\Matching\MatchVerdict;
use App\Entity\NotificationLog;
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
     * @param bool $undecidedOnly Écarte les offres déjà décidées, candidatées ou en doublon
     *
     * @return list<JobMatch>
     */
    public function findTop(int $limit, ?string $searchProfileId = null, bool $eligibleOnly = true, bool $undecidedOnly = false): array
    {
        $query = $this->createQueryBuilder('m')
            ->addSelect('j')
            ->join('m.job', 'j')
            ->orderBy('m.priorityScore', 'DESC')
            ->addOrderBy('m.matchScore', 'DESC')
            ->addOrderBy('j.firstSeenAt', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($undecidedOnly) {
            // Ni décision de l'utilisateur, ni candidature, ni doublon probable : ce qu'il reste à trier.
            $query->andWhere('j.userState IS NULL')
                ->andWhere('j.probableDuplicateOfId IS NULL')
                ->andWhere(sprintf('NOT EXISTS (SELECT a.id FROM %s a WHERE a.job = j)', Application::class));
        }

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
     * Le résultat compatible le plus prioritaire d'une offre, ou `null` si aucun profil ne la juge compatible.
     */
    public function findBestEligible(Job $job): ?JobMatch
    {
        return $this->createQueryBuilder('m')
            ->where('m.job = :job')
            ->andWhere('m.verdict = :eligible')
            ->setParameter('job', $job)
            ->setParameter('eligible', MatchVerdict::Eligible->value)
            ->orderBy('m.priorityScore', 'DESC')
            ->addOrderBy('m.matchScore', 'DESC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Offres compatibles pas encore notifiées, avec leur meilleur résultat, les plus prioritaires d'abord.
     *
     * Écarte les offres déjà notifiées (immédiatement ou en récapitulatif), les doublons probables et celles
     * détectées avant `$detectedSince`. Une offre n'apparaît qu'une fois, même si plusieurs profils la jugent compatible.
     *
     * @return list<JobMatch>
     */
    public function findUnnotified(int $minimumPriority, \DateTimeImmutable $detectedSince, int $limit): array
    {
        /** @var list<JobMatch> $matches */
        $matches = $this->createQueryBuilder('m')
            ->addSelect('j')
            ->join('m.job', 'j')
            ->where('m.verdict = :eligible')
            ->andWhere('m.priorityScore >= :minimum')
            ->andWhere('j.notifiedAt IS NULL')
            ->andWhere('j.userState IS NULL')
            ->andWhere('j.probableDuplicateOfId IS NULL')
            ->andWhere('j.firstSeenAt >= :since')
            ->andWhere(sprintf('NOT EXISTS (SELECT l.id FROM %s l WHERE l.job = j)', NotificationLog::class))
            ->andWhere(sprintf('m.priorityScore = (SELECT MAX(m2.priorityScore) FROM %s m2 WHERE m2.job = m.job AND m2.verdict = :eligible)', JobMatch::class))
            ->setParameter('eligible', MatchVerdict::Eligible->value)
            ->setParameter('minimum', $minimumPriority)
            ->setParameter('since', $detectedSince)
            ->orderBy('m.priorityScore', 'DESC')
            ->addOrderBy('m.matchScore', 'DESC')
            ->addOrderBy('j.firstSeenAt', 'DESC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        $unique = [];
        foreach ($matches as $match) {
            $unique[(int) $match->getJob()->getId()] ??= $match;
        }

        return array_values($unique);
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
