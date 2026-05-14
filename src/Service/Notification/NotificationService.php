<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Job;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gère l'envoi des notifications pour les offres d'emploi jugées pertinentes.
 *
 * Ce service agit comme une façade entre le pipeline de traitement et le canal
 * de notification concret (Telegram). Il garantit qu'une même offre ne génère
 * jamais deux notifications et n'envoie rien en dessous du seuil de score.
 */
final class NotificationService
{
    /** Score minimum requis pour qu'une notification soit envoyée. */
    private const THRESHOLD = 60;

    public function __construct(
        private readonly TelegramNotifier $telegram,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Envoie une notification pour l'offre donnée si les conditions sont remplies.
     *
     * Conditions de blocage (silencieux) :
     *   - L'offre a déjà été notifiée (`notifiedAt` non null)
     *   - Le score est inférieur au seuil de notification
     *
     * En cas de succès, marque l'offre comme notifiée et persiste le changement.
     */
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

    /**
     * Formate le message Telegram en Markdown à partir des données de l'offre.
     */
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
