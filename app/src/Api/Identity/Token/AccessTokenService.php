<?php

declare(strict_types=1);

namespace App\Api\Identity\Token;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Jeton d'accès de l'API : JWT signé HS256, de courte durée, porté en mémoire par le front (en-tête `Bearer`).
 *
 * Sécurité : l'algorithme est **imposé à la vérification** (jamais lu dans le jeton : pas d'attaque `alg=none`),
 * le secret doit faire au moins 32 caractères (sinon l'API d'authentification est désactivée, jamais ouverte),
 * et le jeton ne contient que l'identifiant et les rôles, aucune donnée personnelle.
 */
final readonly class AccessTokenService
{
    private const string ISSUER = 'jobscan';
    private const string ALGORITHM = 'HS256';
    private const int MIN_SECRET_LENGTH = 32;

    public function __construct(
        private string $secret,
        private int $ttl,
        private ClockInterface $clock,
    ) {
    }

    public function isConfigured(): bool
    {
        return \strlen($this->secret) >= self::MIN_SECRET_LENGTH;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function issue(UserInterface $user): string
    {
        $now = $this->clock->now()->getTimestamp();

        return JWT::encode([
            'iss' => self::ISSUER,
            'sub' => $user->getUserIdentifier(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->ttl,
            'jti' => bin2hex(random_bytes(8)),
            'roles' => array_values($user->getRoles()),
        ], $this->secret, self::ALGORITHM);
    }

    /** @return string|null l'identifiant de l'utilisateur, ou null si le jeton est invalide, falsifié ou expiré */
    public function verify(string $jwt): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $previous = JWT::$timestamp;
        JWT::$timestamp = $this->clock->now()->getTimestamp();

        try {
            $claims = JWT::decode($jwt, new Key($this->secret, self::ALGORITHM));
        } catch (\Exception) {
            return null;
        } finally {
            JWT::$timestamp = $previous;
        }

        $subject = $claims->sub ?? null;

        return ($claims->iss ?? null) === self::ISSUER && \is_string($subject) && $subject !== '' ? $subject : null;
    }

    /**
     * Corps de réponse d'une session ouverte (connexion, rafraîchissement) : l'utilisateur et son jeton d'accès.
     *
     * @return array<string, mixed>
     */
    public function session(UserInterface $user): array
    {
        return [
            'user' => self::userView($user),
            'access_token' => $this->issue($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->ttl,
        ];
    }

    /** @return array{id: string, username: string, roles: list<string>} */
    public static function userView(UserInterface $user): array
    {
        return [
            'id' => $user->getUserIdentifier(),
            'username' => $user->getUserIdentifier(),
            'roles' => array_values($user->getRoles()),
        ];
    }
}
