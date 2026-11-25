<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function save(RefreshToken $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    public function findByHash(string $hash): ?RefreshToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /**
     * Marque le jeton comme utilisé **si et seulement s'il ne l'était pas** (une seule requête atomique) : de deux
     * rafraîchissements simultanés avec le même jeton, un seul réussit.
     */
    public function markUsed(RefreshToken $token, \DateTimeImmutable $now): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE refresh_token SET used_at = :now WHERE id = :id AND used_at IS NULL AND revoked_at IS NULL',
            ['now' => $now->format(self::FORMAT), 'id' => $token->getId()],
        );
        $this->getEntityManager()->refresh($token);

        return $affected === 1;
    }

    public function revokeFamily(string $family, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE refresh_token SET revoked_at = :now WHERE family = :family AND revoked_at IS NULL',
            ['now' => $now->format(self::FORMAT), 'family' => $family],
        );
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM refresh_token WHERE expires_at < :now',
            ['now' => $now->format(self::FORMAT)],
        );
    }
}
