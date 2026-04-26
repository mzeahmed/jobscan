<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Job;
use Psr\Log\LoggerInterface;

final class NotificationService
{
    private const THRESHOLD = 60;

    public function __construct(
        private readonly TelegramNotifier $telegram,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(Job $job): void
    {
        if ($job->getScore() < self::THRESHOLD) {
            return;
        }

        $message = $this->formatMessage($job);

        $this->telegram->send($message);

        $this->logger->info('Notification envoyée', [
            'title' => $job->getTitle(),
            'score' => $job->getScore(),
        ]);
    }

    private function formatMessage(Job $job): string
    {
        return sprintf(
            "*Nouvelle opportunité détectée*\n\n" .
            "*Titre* : %s\n" .
            "*Score* : %d/100\n\n" .
            '%s',
            $job->getTitle(),
            $job->getScore(),
            $job->getUrl()
        );
    }
}
