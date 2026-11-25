<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\RefreshTokenRepository;

/**
 * Jeton de rafraîchissement de l'API : à usage unique, renouvelé à chaque utilisation (rotation).
 *
 * Seule l'empreinte SHA-256 du jeton est stockée : une fuite de la base ne donne aucun jeton utilisable (le jeton
 * est un secret aléatoire de 256 bits, une empreinte rapide suffit). Les jetons issus d'une même connexion partagent
 * une `family` : si un jeton déjà utilisé est présenté de nouveau (vol probable), toute la famille est révoquée.
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_token')]
#[ORM\Index(name: 'idx_refresh_token_family', columns: ['family'])]
#[ORM\Index(name: 'idx_refresh_token_expires', columns: ['expires_at'])]
#[ORM\UniqueConstraint(name: 'uniq_refresh_token_hash', columns: ['token_hash'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $tokenHash,
        #[ORM\Column(length: 32)]
        private string $family,
        #[ORM\Column(length: 180)]
        private string $username,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFamily(): string
    {
        return $this->family;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }
}
