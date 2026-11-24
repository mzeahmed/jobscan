<?php

declare(strict_types=1);

namespace App\Tracking;

/**
 * Décision de l'utilisateur sur une offre, indépendante d'une candidature. Une offre sans décision est « à trier ».
 *
 * Toute offre avec une décision est conservée par la purge (« historique utilisateur »).
 */
enum JobUserState: string
{
    /** À garder sous la main. */
    case Saved = 'saved';
    /** Sans intérêt : plus notifiée ni proposée, mais conservée pour qu'une nouvelle collecte ne la fasse pas revenir. */
    case Ignored = 'ignored';
    /** Mise de côté sans suppression. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Saved => 'Sauvegardée',
            self::Ignored => 'Ignorée',
            self::Archived => 'Archivée',
        };
    }

    /** Une offre ignorée ou archivée n'est plus notifiée ni proposée dans les classements. */
    public function isHidden(): bool
    {
        return $this === self::Ignored || $this === self::Archived;
    }
}
