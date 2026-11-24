<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Message de récapitulatif prêt à envoyer, et nombre d'offres qu'il reprend réellement (le message est borné).
 */
final readonly class DigestMessage
{
    public function __construct(
        public string $text,
        public int $included,
    ) {
    }
}
