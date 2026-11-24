<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Job;
use App\Entity\JobMatch;
use Psr\Log\LoggerInterface;
use App\Entity\NotificationLog;
use App\Profile\ProfileRegistry;
use App\Repository\JobMatchRepository;
use App\AI\Extraction\ExtractionSource;
use App\Profile\InvalidProfileException;
use App\Repository\NotificationLogRepository;

/**
 * Notifications Telegram fondées sur le matching : niveau, notification immédiate, sans doublon.
 *
 * Une offre est notifiée **au plus une fois** (immédiatement ou dans un récapitulatif) : `notified_at` (ancien
 * mécanisme) et `notification_log` en font foi. Ne sont concernées que les offres compatibles avec au moins un
 * profil de recherche, dont l'analyse est définitive et qui ne sont pas un doublon probable. Les résultats sont
 * persistés ; l'appelant se charge du `flush`.
 */
final readonly class MatchNotifier
{
    public function __construct(
        private ProfileRegistry $profiles,
        private JobMatchRepository $matches,
        private NotificationLogRepository $logs,
        private TelegramNotifier $telegram,
        private TelegramMessageFormatter $formatter,
        private NotificationPolicy $policy,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Vrai quand un candidat et au moins un profil de recherche actif sont configurés : les notifications suivent
     * alors le matching. Sinon (ou si les fichiers de profil sont invalides), l'ancien mécanisme reste en vigueur.
     */
    public function isActive(): bool
    {
        try {
            $set = $this->profiles->profiles();
        } catch (InvalidProfileException $e) {
            $this->logger->warning('Notifications fondées sur le matching désactivées : profil invalide.', ['error' => $e->getMessage()]);

            return false;
        }

        return $set->candidate !== null && $set->enabledSearchProfiles() !== [];
    }

    /**
     * Notifie immédiatement l'offre si son niveau l'exige (prioritaire, ou intéressante en mode immédiat).
     */
    public function notify(Job $job, \DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        if (!$this->canNotify($job)) {
            return false;
        }

        $best = $this->matches->findBestEligible($job);

        return $best !== null && $this->sendIfImmediate($best, $now);
    }

    /**
     * Rattrape les notifications immédiates manquées (Telegram indisponible au moment de l'analyse), parmi les offres
     * récemment détectées.
     *
     * @return int Nombre de notifications envoyées
     */
    public function catchUp(\DateTimeImmutable $now, int $limit = 10): int
    {
        $sent = 0;
        $candidates = $this->matches->findUnnotified($this->policy->minimumPriority(), $this->policy->oldestNotifiable($now), $limit * 5);

        foreach ($candidates as $match) {
            if ($sent >= $limit) {
                break;
            }

            if ($this->canNotify($match->getJob()) && $this->sendIfImmediate($match, $now)) {
                ++$sent;
            }
        }

        return $sent;
    }

    private function sendIfImmediate(JobMatch $match, \DateTimeImmutable $now): bool
    {
        $level = $this->policy->levelFor($match->getPriorityScore(), $match->getResult()->requirementsSource ?? ExtractionSource::Llm);
        if ($level === null || $this->policy->mode($level) !== NotificationMode::Immediate) {
            return false;
        }

        $job = $match->getJob();
        $message = $this->formatter->immediate($match, $level, $this->profileName($match->getSearchProfileId()), $this->cvLabel($match->getCvId()), $now);

        if (!$this->telegram->send($message, $this->policy->isSilent($level))) {
            $this->logger->warning('Notification non marquée : envoi Telegram en échec.', ['title' => $job->getTitle()]);

            return false;
        }

        $job->markAsNotified();
        $this->logs->save(new NotificationLog($job, NotificationLog::KIND_IMMEDIATE, $level, $match->getSearchProfileId(), $match->getPriorityScore(), $now));
        $this->logger->info('Notification envoyée', ['title' => $job->getTitle(), 'level' => $level->value, 'priority' => $match->getPriorityScore()]);

        return true;
    }

    private function canNotify(Job $job): bool
    {
        return $job->getNotifiedAt() === null
            && $job->isAnalysisFinal()
            && $job->getUserState() === null
            && $job->getProbableDuplicateOfId() === null
            && !$this->logs->existsForJob($job);
    }

    private function profileName(string $searchProfileId): string
    {
        return $this->profiles->searchProfile($searchProfileId)->name ?? $searchProfileId;
    }

    private function cvLabel(?string $cvId): ?string
    {
        if ($cvId === null) {
            return null;
        }

        $cv = $this->profiles->candidate()?->cvVersion($cvId);

        return $cv->label ?? $cvId;
    }
}
