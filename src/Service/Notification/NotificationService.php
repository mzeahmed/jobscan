<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Job;
use Psr\Log\LoggerInterface;

final class NotificationService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Notifie qu'une offre pertinente a été détectée.
     *
     * En production: remplacer l'echo par un appel Slack webhook,
     * symfony/mailer, ou tout autre canal de notification.
     */
    public function notify(Job $job): void
    {
        $message = \sprintf(
            '[OPPSCAN] Nouvelle opportunité (%d/100) : %s — %s',
            $job->getScore(),
            $job->getTitle(),
            $job->getUrl(),
        );

        $this->logger->info($message);

        file_put_contents(
            __DIR__ . '/../../../var/alerts.log',
            $message . PHP_EOL,
            FILE_APPEND
        );

        // Notification console visible lors de l'exécution de la commande
        echo $message . \PHP_EOL;
    }
}
