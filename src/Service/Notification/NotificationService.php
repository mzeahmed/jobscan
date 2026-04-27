<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Job;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;

final class NotificationService
{
    private const THRESHOLD = 60;

    public function __construct(
        private readonly TelegramNotifier $telegram,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function notify(Job $job): void
    {
        if ($job->getNotifiedAt() !== null) {
            $this->logger->debug('Notification ignorée : déjà envoyée.', [
                'title' => $job->getTitle(),
                'notified_at' => $job->getNotifiedAt()->format('Y-m-d H:i:s'),
            ]);

            return;
        }

        if ($job->getScore() < self::THRESHOLD) {
            return;
        }

        $this->telegram->send($this->formatMessage($job));

        $job->markAsNotified();
        $this->em->flush();

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
