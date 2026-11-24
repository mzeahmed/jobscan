<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobSource;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<JobSource>
 */
class JobSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobSource::class);
    }

    public function save(JobSource $source): void
    {
        $this->getEntityManager()->persist($source);
    }

    /**
     * Retrouve l'observation d'un provider pour une offre : même URL canonique, ou
     * même identifiant externe chez ce provider. Deux providers qui remontent la même
     * URL ont chacun leur propre observation.
     */
    public function findByIdentity(string $canonicalUrl, string $provider, ?string $externalId): ?JobSource
    {
        $match = 's.canonicalUrl = :canonicalUrl';
        if ($externalId !== null) {
            $match .= ' OR s.externalId = :externalId';
        }

        $query = $this->createQueryBuilder('s')
            ->where('s.provider = :provider')
            ->andWhere($match)
            ->setParameter('provider', $provider)
            ->setParameter('canonicalUrl', $canonicalUrl);

        if ($externalId !== null) {
            $query->setParameter('externalId', $externalId);
        }

        return $query->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return list<JobSource> */
    public function findByJob(int $jobId): array
    {
        return $this->findBy(['job' => $jobId], ['firstSeenAt' => 'ASC']);
    }
}
