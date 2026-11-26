<?php

declare(strict_types=1);

namespace App\Reminder;

use App\Entity\Application;
use Psr\Log\LoggerInterface;
use App\Notification\TelegramHtml;
use App\Telegram\ReminderKeyboard;
use App\Notification\TelegramNotifier;
use App\Repository\ApplicationRepository;

/**
 * Envoie, par Telegram, un rappel pour chaque relance **que vous avez planifiée** et dont la date est atteinte.
 *
 * - Un seul rappel par date de relance : il est enregistré seulement si Telegram a bien reçu le message, et remis à zéro
 *   si la date change (report, nouvelle date).
 * - Aucune relance n'est déduite : sans date saisie, rien ne part. Les brouillons et les candidatures terminées (acceptée,
 *   refusée, retirée) ne déclenchent rien.
 * - Le message ne contient ni contact ni nom de recruteur : ce sont des données personnelles d'un tiers, qui n'ont pas à transiter par Telegram.
 */
final readonly class FollowUpReminder
{
    public function __construct(
        private ApplicationRepository $applications,
        private TelegramNotifier $telegram,
        private LoggerInterface $logger,
    ) {
    }

    public function send(\DateTimeImmutable $now, bool $dryRun = false, int $limit = 10): ReminderResult
    {
        $due = $this->applications->findDueFollowUps($now, $limit);
        $sent = 0;
        $previews = [];

        foreach ($due as $application) {
            $message = $this->message($application, $now);
            $previews[] = $message;

            if ($dryRun) {
                continue;
            }

            if (!$this->telegram->send($message, false, false, ReminderKeyboard::forApplication((int) $application->getId()))) {
                $this->logger->warning('Rappel de relance non marqué : envoi Telegram en échec.', ['application' => $application->getId()]);

                continue;
            }

            $application->markReminded($now);
            ++$sent;
        }

        return new ReminderResult(count($due), $sent, $previews);
    }

    private function message(Application $application, \DateTimeImmutable $now): string
    {
        $job = $application->getJob();
        $lines = ['⏰ <b>Relance à faire</b>', sprintf('<b>%s</b>', TelegramHtml::escape(mb_substr((string) $job->getTitle(), 0, 200)))];

        if ($job->getCompany() !== null) {
            $lines[] = TelegramHtml::escape(mb_substr($job->getCompany(), 0, 80));
        }

        $facts = [sprintf('Statut : %s', $application->getStatus()->label())];
        $applied = $application->getAppliedAt();
        if ($applied !== null) {
            $facts[] = sprintf('postulé le %s (%s)', $applied->format('d/m/Y'), self::elapsed($applied, $now));
        }

        $lines[] = TelegramHtml::escape(implode(' · ', $facts));

        $followUp = $application->getFollowUpAt();
        if ($followUp !== null) {
            $overdue = (int) floor(($now->getTimestamp() - $followUp->getTimestamp()) / 86400);
            $lines[] = sprintf('Relance prévue le %s%s', $followUp->format('d/m/Y'), $overdue >= 1 ? sprintf(' — en retard de %d j', $overdue) : '');
        }

        $contact = $application->getLastContactAt();
        if ($contact !== null) {
            $lines[] = sprintf('Dernier contact : %s', self::elapsed($contact, $now));
        }

        $lines[] = TelegramHtml::link((string) $job->getUrl(), 'Voir l\'offre');

        return implode("\n", $lines);
    }

    private static function elapsed(\DateTimeImmutable $from, \DateTimeImmutable $now): string
    {
        $days = max(0, (int) floor(($now->getTimestamp() - $from->getTimestamp()) / 86400));

        return $days === 0 ? 'aujourd\'hui' : sprintf('il y a %d j', $days);
    }
}
