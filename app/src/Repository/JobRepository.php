<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Entity\JobMatch;
use App\Entity\JobSource;
use App\DTO\AnalysisStatus;
use App\Entity\Application;
use App\DTO\JobListingFilters;
use Doctrine\ORM\QueryBuilder;
use App\Entity\NotificationLog;
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

    /**
     * Retrouve l'offre déjà connue pour une identité **certaine** : même URL, même URL
     * canonique, ou même identifiant externe chez un même provider (y compris via les
     * sources secondaires d'une offre).
     */
    public function findCertainDuplicate(string $url, string $canonicalUrl, string $provider, ?string $externalId): ?Job
    {
        $sourceMatch = 's.canonicalUrl = :canonicalUrl';
        if ($externalId !== null) {
            $sourceMatch .= ' OR (s.provider = :provider AND s.externalId = :externalId)';
        }

        $query = $this->createQueryBuilder('j')
            ->where('j.url = :url')
            ->orWhere('j.canonicalUrl = :canonicalUrl')
            ->orWhere(sprintf(
                'j.id IN (SELECT IDENTITY(s.job) FROM %s s WHERE %s)',
                JobSource::class,
                $sourceMatch,
            ))
            ->setParameter('url', $url)
            ->setParameter('canonicalUrl', $canonicalUrl);

        if ($externalId !== null) {
            $query->setParameter('provider', $provider)->setParameter('externalId', $externalId);
        }

        return $query->orderBy('j.id', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * Identifiant de la plus ancienne offre partageant l'empreinte titre + entreprise + lieu.
     *
     * Une empreinte identique est un indice de doublon probable, jamais une preuve.
     */
    public function findFirstIdByFingerprint(string $fingerprint): ?int
    {
        $id = $this->createQueryBuilder('j')
            ->select('j.id')
            ->where('j.fingerprint = :fingerprint')
            ->setParameter('fingerprint', $fingerprint)
            ->orderBy('j.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return is_array($id) ? (int) $id['id'] : null;
    }

    /**
     * Offres dont l'analyse est à faire ou à retenter : en attente, analysées
     * seulement par l'heuristique, ou en échec — tant que le nombre maximal de
     * tentatives n'est pas atteint et que la prochaine tentative est échue.
     * Les plus récemment détectées passent en premier.
     *
     * @return list<Job>
     */
    public function findDueForAnalysis(\DateTimeImmutable $now, int $maxAttempts, int $limit): array
    {
        return array_values($this->createQueryBuilder('j')
            ->where('j.analysisStatus IN (:statuses)')
            ->andWhere('j.analysisAttempts < :maxAttempts')
            ->andWhere('j.nextAnalysisAttemptAt IS NULL OR j.nextAnalysisAttemptAt <= :now')
            ->setParameter('statuses', [
                AnalysisStatus::Pending->value,
                AnalysisStatus::Fallback->value,
                AnalysisStatus::Failed->value,
            ])
            ->setParameter('maxAttempts', $maxAttempts)
            ->setParameter('now', $now)
            ->orderBy('j.firstSeenAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult());
    }

    /**
     * Offres analysées par le LLM dont les exigences structurées manquent ou datent d'un schéma plus ancien
     * que `$schemaVersion`. Les plus récemment détectées passent en premier.
     *
     * Les offres analysées seulement par l'heuristique n'en font pas partie : elles seront reprises par la
     * reprise d'analyse ordinaire, qui produira directement le schéma courant.
     *
     * @return list<Job>
     */
    public function findOutdatedExtraction(int $schemaVersion, int $limit): array
    {
        return array_values($this->createQueryBuilder('j')
            ->where('j.analysisStatus = :done')
            ->andWhere('j.extractionVersion IS NULL OR j.extractionVersion < :version')
            ->setParameter('done', AnalysisStatus::Done->value)
            ->setParameter('version', $schemaVersion)
            ->orderBy('j.firstSeenAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult());
    }

    public function countOutdatedExtraction(int $schemaVersion): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.analysisStatus = :done')
            ->andWhere('j.extractionVersion IS NULL OR j.extractionVersion < :version')
            ->setParameter('done', AnalysisStatus::Done->value)
            ->setParameter('version', $schemaVersion)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Offres disposant d'exigences extraites, par ordre d'identifiant, pour le matching par lots.
     *
     * @return list<Job>
     */
    public function findWithRequirementsAfterId(int $afterId, int $limit): array
    {
        return array_values($this->createQueryBuilder('j')
            ->where('j.id > :after')
            ->andWhere('j.requirements IS NOT NULL')
            ->setParameter('after', $afterId)
            ->orderBy('j.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult());
    }

    /** @return array<string, int> */
    public function countByAnalysisStatus(): array
    {
        /** @var list<array{status: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('j')
            ->select('j.analysisStatus AS status, COUNT(j.id) AS total')
            ->groupBy('j.analysisStatus')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
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
            $connection->executeStatement('DELETE FROM job_application_status_change');
            $connection->executeStatement('DELETE FROM job_application');
            $connection->executeStatement('DELETE FROM notification_log');
            $connection->executeStatement('DELETE FROM job_match');
            $connection->executeStatement('DELETE FROM job_source');
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

        match ($filters->state) {
            'all' => null,
            'saved', 'ignored', 'archived' => $query->andWhere('j.userState = :userState')->setParameter('userState', $filters->state),
            default => $query->andWhere("j.userState IS NULL OR j.userState = 'saved'"),
        };

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
            ->andWhere(self::purgeable('j'))
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Offres anciennes que la purge épargne : décision de l'utilisateur ou candidature associée (historique utilisateur). */
    public function countProtectedCreatedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.createdAt < :before')
            ->andWhere('NOT (' . self::purgeable('j') . ')')
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Condition DQL « cette offre peut être purgée » : aucune décision utilisateur ni candidature. Un alias distinct
     * par appel évite les collisions dans les sous-requêtes.
     */
    private static function purgeable(string $alias): string
    {
        return sprintf(
            '%1$s.userState IS NULL AND NOT EXISTS (SELECT pa_%1$s.id FROM %2$s pa_%1$s WHERE pa_%1$s.job = %1$s)',
            $alias,
            Application::class,
        );
    }

    /**
     * Supprime les offres ingérées (`createdAt`) strictement avant la date donnée.
     *
     * @return int Nombre d'offres supprimées
     */
    public function deleteCreatedBefore(\DateTimeImmutable $before): int
    {
        // Sources et résultats de matching sont supprimés explicitement : SQLite n'applique pas
        // ON DELETE CASCADE sans `PRAGMA foreign_keys`.
        $this->getEntityManager()->createQueryBuilder()
            ->delete(NotificationLog::class, 'n')
            ->where('n.job IN (SELECT j3.id FROM ' . Job::class . ' j3 WHERE j3.createdAt < :before AND ' . self::purgeable('j3') . ')')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        $this->getEntityManager()->createQueryBuilder()
            ->delete(JobMatch::class, 'm')
            ->where('m.job IN (SELECT j2.id FROM ' . Job::class . ' j2 WHERE j2.createdAt < :before AND ' . self::purgeable('j2') . ')')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        $this->getEntityManager()->createQueryBuilder()
            ->delete(JobSource::class, 's')
            ->where('s.job IN (SELECT j.id FROM ' . Job::class . ' j WHERE j.createdAt < :before AND ' . self::purgeable('j') . ')')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        return (int) $this->createQueryBuilder('j')
            ->delete()
            ->where('j.createdAt < :before')
            ->andWhere(self::purgeable('j'))
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
