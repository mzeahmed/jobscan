<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Bilan d'un récapitulatif : offres candidates, reprises dans le message, laissées pour la prochaine fois.
 */
final readonly class DigestResult
{
    public function __construct(
        public int $candidates,
        public int $included,
        public bool $sent,
        public string $text = '',
    ) {
    }

    public function remaining(): int
    {
        return $this->candidates - $this->included;
    }
}
