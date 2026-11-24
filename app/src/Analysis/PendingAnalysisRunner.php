<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Entity\Job;
use App\Matching\JobMatcher;
use Psr\Log\LoggerInterface;
use App\Notification\Notifier;
use App\Repository\JobRepository;
use App\AI\Extraction\JobRequirements;

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
        private ?JobMatcher $matcher = null,
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

            if ($outcome->succeeded) {
                $this->match($job, $now);
            }

            // L'analyse et son matching sont persistés avant toute notification.
            $this->jobRepository->flush();

            if ($outcome->succeeded && $this->notifier->notify($job)) {
                ++$notified;
                $this->jobRepository->flush();
            }
        }

        $this->jobRepository->clear();

        return new AnalysisSummary($ai, $fallback, $failed, $notified);
    }

    public function reextractOutdated(int $limit, \DateTimeImmutable $now = new \DateTimeImmutable()): AnalysisSummary
    {
        $this->analyzer->startBatch();
        $jobs = $this->jobRepository->findOutdatedExtraction(JobRequirements::SCHEMA_VERSION, $limit);
        $updated = $skipped = $failed = 0;

        foreach ($jobs as $job) {
            $outcome = $this->analyzer->reextract($job, $now);

            if (!$outcome->succeeded) {
                ++$failed;
                $this->logger->warning('Ré-extraction en échec pour "{title}" : {error}', [
                    'title' => $job->getTitle(),
                    'error' => $outcome->error,
                ]);
            } elseif ($outcome->usedFallback) {
                ++$skipped;
            } else {
                ++$updated;
                $this->match($job, $now, true);
                $this->jobRepository->flush();
            }
        }

        $this->jobRepository->clear();

        return new AnalysisSummary(analyzedByAi: $updated, failed: $failed, skipped: $skipped);
    }

    /**
     * Apparie l'offre aux profils de recherche. Le matching est un enrichissement : son échec (profil
     * invalide, par exemple) ne remet jamais en cause l'analyse, qui est déjà acquise.
     */
    private function match(Job $job, \DateTimeImmutable $now, bool $force = false): void
    {
        try {
            $this->matcher?->matchJob($job, $now, $force);
        } catch (\Throwable $e) {
            $this->logger->warning('Matching impossible pour "{title}" : {error}', [
                'title' => $job->getTitle(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
