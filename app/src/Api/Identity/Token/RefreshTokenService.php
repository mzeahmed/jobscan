<?php

declare(strict_types=1);

namespace App\Api\Identity\Token;

use App\Entity\RefreshToken;
use Psr\Log\LoggerInterface;
use App\Repository\RefreshTokenRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Cycle de vie des jetons de rafraîchissement : émission, rotation à usage unique, révocation.
 *
 * Un jeton ne sert qu'une fois. Le présenter une seconde fois (vol probable, ou deux onglets non coordonnés)
 * révoque toute la famille : il faut se reconnecter.
 */
final readonly class RefreshTokenService
{
    public function __construct(
        private RefreshTokenRepository $tokens,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $ttl,
    ) {
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    /** Ouvre une nouvelle famille (connexion). @return string le jeton en clair, à poser en cookie, jamais conservé */
    public function start(string $username): string
    {
        $this->tokens->deleteExpired($this->clock->now());

        return $this->issue($username, bin2hex(random_bytes(16)));
    }

    /**
     * Échange un jeton contre le suivant de la même famille.
     *
     * @return array{username: string, token: string}
     *
     * @throws InvalidRefreshTokenException
     */
    public function rotate(string $raw): array
    {
        $now = $this->clock->now();
        $token = $this->tokens->findByHash(self::hash($raw));

        if ($token === null) {
            throw new InvalidRefreshTokenException();
        }

        if ($token->getRevokedAt() !== null || $token->getExpiresAt() <= $now) {
            throw new InvalidRefreshTokenException();
        }

        if ($token->getUsedAt() !== null || !$this->tokens->markUsed($token, $now)) {
            $this->tokens->revokeFamily($token->getFamily(), $now);
            $this->logger->warning('Jeton de rafraîchissement réutilisé : famille révoquée.', ['family' => $token->getFamily()]);

            throw new InvalidRefreshTokenException();
        }

        return ['username' => $token->getUsername(), 'token' => $this->issue($token->getUsername(), $token->getFamily())];
    }

    /** Révoque la famille du jeton présenté (déconnexion). Sans effet si le jeton est inconnu. */
    public function revoke(string $raw): void
    {
        $token = $this->tokens->findByHash(self::hash($raw));

        if ($token !== null) {
            $this->tokens->revokeFamily($token->getFamily(), $this->clock->now());
        }
    }

    private function issue(string $username, string $family): string
    {
        $raw = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->tokens->save(new RefreshToken(self::hash($raw), $family, $username, $now, $now->modify(sprintf('+%d seconds', $this->ttl))));

        return $raw;
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
