<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Job;
use Psr\Log\LoggerInterface;

/**
 * Gère l'envoi des notifications pour les offres d'emploi jugées pertinentes.
 *
 * Ce service agit comme une façade entre le pipeline de traitement et le canal
 * de notification concret (Telegram). Il garantit qu'une même offre ne génère
 * jamais deux notifications et n'envoie rien en dessous du seuil de score.
 */
final readonly class Notifier
{
    public function __construct(
        private TelegramNotifier $telegram,
        private LoggerInterface $logger,
        private int $scoreThreshold = 60,
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
    public function notify(Job $job): bool
    {
        if ($job->getNotifiedAt() !== null) {
            $this->logger->debug('Notification ignorée : déjà envoyée.', [
                'title' => $job->getTitle(),
                'notified_at' => $job->getNotifiedAt()->format('Y-m-d H:i:s'),
            ]);

            return false;
        }

        if ($job->getScore() < $this->scoreThreshold) {
            return false;
        }

        if (!$this->telegram->send($this->formatMessage($job))) {
            $this->logger->warning('Notification non marquée : envoi Telegram en échec.', [
                'title' => $job->getTitle(),
            ]);

            return false;
        }

        $job->markAsNotified();

        $this->logger->info('Notification envoyée', [
            'title' => $job->getTitle(),
            'score' => $job->getScore(),
        ]);

        return true;
    }

    /**
     * Formate le message Telegram en HTML en échappant toutes les données externes.
     */
    private function formatMessage(Job $job): string
    {
        $title = htmlspecialchars((string) $job->getTitle(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $url = htmlspecialchars((string) $job->getUrl(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return sprintf(
            "<b>Nouvelle opportunité détectée</b>\n\n" .
            "<b>Titre</b> : %s\n" .
            "<b>Score</b> : %d/100\n\n" .
            '<a href="%s">Voir l’offre</a>',
            $title,
            $job->getScore(),
            $url,
        );
    }
}
