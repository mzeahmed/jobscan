<?php

declare(strict_types=1);

namespace App\Analysis;

use Psr\Log\LoggerInterface;
use App\Notification\Notifier;
use App\Repository\JobRepository;

/**
 * Analyse les offres persistées en attente ou à retenter, puis notifie celles dont
 * l'analyse est définitive.
 *
 * La collecte ne dépend jamais de ce service : si le LLM est indisponible, les offres
 * restent en base avec un statut d'analyse et sont reprises à l'exécution suivante.
 */
final readonly class PendingAnalysisRunner implements PendingAnalysisRunnerInterface
{
    public function __construct(
        private JobRepository $jobRepository,
        private JobAnalyzer $analyzer,
        private Notifier $notifier,
        private AnalysisRetryPolicy $retryPolicy,
        private LoggerInterface $logger,
    ) {
    }

    public function run(int $limit, \DateTimeImmutable $now = new \DateTimeImmutable()): AnalysisSummary
    {
        $this->analyzer->startBatch();
        $jobs = $this->jobRepository->findDueForAnalysis($now, $this->retryPolicy->maxAttempts(), $limit);
        $ai = $fallback = $failed = $notified = 0;

        foreach ($jobs as $job) {
            $outcome = $this->analyzer->analyze($job, $now);

            if (!$outcome->succeeded) {
                ++$failed;
                $this->logger->warning('Analyse en échec pour "{title}" : {error}', [
                    'title' => $job->getTitle(),
                    'error' => $outcome->error,
                ]);
            } elseif ($outcome->usedFallback) {
                ++$fallback;
            } else {
                ++$ai;
            }

            // L'analyse est persistée avant toute notification.
            $this->jobRepository->flush();

            if ($outcome->succeeded && $this->notifier->notify($job)) {
                ++$notified;
                $this->jobRepository->flush();
            }
        }

        $this->jobRepository->clear();

        return new AnalysisSummary($ai, $fallback, $failed, $notified);
    }
}
