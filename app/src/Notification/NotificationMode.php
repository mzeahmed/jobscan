<?php

declare(strict_types=1);

namespace App\Notification;

enum NotificationMode: string
{
    /** Notification dès l'analyse de l'offre. */
    case Immediate = 'immediate';
    /** Reprise dans le récapitulatif périodique. */
    case Digest = 'digest';
    /** Aucune notification. */
    case Off = 'off';
}
