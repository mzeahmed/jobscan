<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Niveau d'une offre pour la notification, selon son score de priorité.
 */
enum NotificationLevel: string
{
    /** Opportunité prioritaire : notification immédiate. */
    case Priority = 'priority';
    /** Offre intéressante : notification selon la configuration. */
    case Interesting = 'interesting';
    /** Offre secondaire : récapitulatif périodique. */
    case Secondary = 'secondary';

    public function label(): string
    {
        return match ($this) {
            self::Priority => 'Opportunité prioritaire',
            self::Interesting => 'Offre intéressante',
            self::Secondary => 'Offre secondaire',
        };
    }
}
