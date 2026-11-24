<?php

declare(strict_types=1);

namespace App\Notification;

use Psr\Log\LoggerInterface;
use App\Entity\NotificationLog;
use App\Profile\ProfileRegistry;
use App\Repository\JobMatchRepository;
use App\AI\Extraction\ExtractionSource;
use App\Repository\NotificationLogRepository;

/**
 * Récapitulatif périodique des offres dont le niveau est configuré en « récapitulatif » (offres secondaires
 * et, selon la configuration, intéressantes).
 *
 * Un seul message, borné, qui reprend les offres les plus prioritaires. Seules les offres **effectivement reprises**
 * sont marquées comme notifiées : les autres figureront dans le prochain récapitulatif. Si l'envoi échoue, rien
 * n'est marqué. Les résultats sont persistés ; l'appelant se charge du `flush`.
 */
final readonly class DigestNotifier
{
    private const int CANDIDATE_LIMIT = 500;

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

    public function send(\DateTimeImmutable $now = new \DateTimeImmutable(), bool $dryRun = false, ?int $maxItems = null): DigestResult
    {
        $items = $this->candidates($now, $maxItems ?? $this->policy->digestMaxItems());
        if ($items === []) {
            return new DigestResult(0, 0, false);
        }

        $message = $this->formatter->digest($items, $now);
        if ($dryRun) {
            return new DigestResult(count($items), $message->included, false, $message->text);
        }

        // Plusieurs offres : l'aperçu des liens noierait le message.
        if (!$this->telegram->send($message->text, true, false)) {
            $this->logger->warning('Récapitulatif non marqué : envoi Telegram en échec.');

            return new DigestResult(count($items), $message->included, false, $message->text);
        }

        foreach (array_slice($items, 0, $message->included) as $item) {
            $this->logs->save(new NotificationLog(
                $item->match->getJob(),
                NotificationLog::KIND_DIGEST,
                $item->level,
                $item->match->getSearchProfileId(),
                $item->match->getPriorityScore(),
                $now,
            ));
        }

        $this->logger->info('Récapitulatif envoyé', ['offers' => $message->included]);

        return new DigestResult(count($items), $message->included, true, $message->text);
    }

    /**
     * @return list<DigestItem> Du plus au moins prioritaire
     */
    private function candidates(\DateTimeImmutable $now, int $maxItems): array
    {
        $items = [];
        foreach ($this->matches->findUnnotified($this->policy->minimumPriority(), $this->policy->oldestNotifiable($now), self::CANDIDATE_LIMIT) as $match) {
            $level = $this->policy->levelFor($match->getPriorityScore(), $match->getResult()->requirementsSource ?? ExtractionSource::Llm);
            if ($level === null || $this->policy->mode($level) !== NotificationMode::Digest || !$match->getJob()->isAnalysisFinal()) {
                continue;
            }

            $items[] = new DigestItem($match, $level, $this->profiles->searchProfile($match->getSearchProfileId())->name ?? $match->getSearchProfileId());
        }

        return array_slice($items, 0, max(1, $maxItems));
    }
}
