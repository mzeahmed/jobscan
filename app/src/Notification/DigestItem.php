<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\JobMatch;

/**
 * Offre candidate à un récapitulatif, avec son meilleur résultat de matching.
 */
final readonly class DigestItem
{
    public function __construct(
        public JobMatch $match,
        public NotificationLevel $level,
        public string $profileName,
    ) {
    }
}
