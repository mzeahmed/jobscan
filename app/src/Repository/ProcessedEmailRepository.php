<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProcessedEmail;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<ProcessedEmail>
 */
class ProcessedEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessedEmail::class);
    }

    public function isProcessed(string $dedupKey): bool
    {
        return $this->count(['dedupKey' => $dedupKey]) > 0;
    }

    public function save(ProcessedEmail $email): void
    {
        $this->getEntityManager()->persist($email);
        $this->getEntityManager()->flush();
    }
}
