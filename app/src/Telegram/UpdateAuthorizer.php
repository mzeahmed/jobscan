<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * Seul le chat configuré (et, s'il s'agit d'un groupe, seuls les utilisateurs listés) peut agir sur Jobscan.
 *
 * - Chat privé (identifiant positif) : l'expéditeur doit être le titulaire du chat.
 * - Groupe ou canal (identifiant négatif) : tout membre pourrait appuyer sur un bouton ; les actions restent donc
 *   **désactivées** tant que `TELEGRAM_ALLOWED_USER_IDS` ne liste pas explicitement les utilisateurs autorisés.
 *
 * Une mise à jour non autorisée est ignorée sans réponse : le bot ne confirme pas son existence à un inconnu.
 */
final readonly class UpdateAuthorizer
{
    /** @var list<int> */
    private array $allowedUsers;

    /** @param string $allowedUserIds Identifiants d'utilisateurs séparés par des virgules */
    public function __construct(private string $chatId, string $allowedUserIds = '')
    {
        $this->allowedUsers = array_values(array_map(intval(...), array_filter(array_map(trim(...), explode(',', $allowedUserIds)), static fn (string $id): bool => preg_match('/^\d+$/', $id) === 1)));
    }

    public function isEnabled(): bool
    {
        return $this->isPrivateChat() || $this->allowedUsers !== [];
    }

    public function isAuthorized(int $chatId, int $userId): bool
    {
        if ((string) $chatId !== $this->chatId || !$this->isEnabled()) {
            return false;
        }

        return $this->isPrivateChat() ? $userId === $chatId : in_array($userId, $this->allowedUsers, true);
    }

    private function isPrivateChat(): bool
    {
        return preg_match('/^\d+$/', $this->chatId) === 1;
    }
}
