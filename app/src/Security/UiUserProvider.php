<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;

/**
 * Le compte unique de l'interface web, défini par l'environnement (`JOBSCAN_UI_USER`, `JOBSCAN_UI_PASSWORD_HASH`).
 *
 * Seul un **hash** est configuré, jamais le mot de passe. Tant qu'aucun hash n'est défini, aucun compte n'existe :
 * l'interface reste inaccessible plutôt qu'ouverte.
 *
 * @implements UserProviderInterface<InMemoryUser>
 */
final readonly class UiUserProvider implements UserProviderInterface
{
    public function __construct(
        private string $username,
        private string $passwordHash,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->username) !== '' && str_starts_with($this->passwordHash, '$');
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if (!$this->isConfigured() || !hash_equals($this->username, $identifier)) {
            throw new UserNotFoundException();
        }

        return new InMemoryUser($this->username, $this->passwordHash, ['ROLE_USER']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof InMemoryUser) {
            throw new UnsupportedUserException(sprintf('Utilisateur « %s » non pris en charge.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === InMemoryUser::class;
    }
}
