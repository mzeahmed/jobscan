<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Entity\Job;
use App\AI\AIClient;
use App\Scoring\Scoring;

/**
 * Analyse une offre déjà constituée : extraction LLM (ou heuristique), puis score.
 *
 * Le service ne persiste rien et ne notifie pas : l'appelant décide (le pipeline
 * persiste d'abord, le mode dry-run n'écrit rien). Les erreurs n'interrompent
 * jamais l'appelant : elles sont consignées sur l'offre pour permettre la reprise.
 */
final readonly class JobAnalyzer
{
    public function __construct(
        private AIClient $aiClient,
        private Scoring $scoring,
        private AnalysisRetryPolicy $retryPolicy,
    ) {
    }

    /**
     * À appeler avant chaque lot : un LLM jugé indisponible lors du lot précédent est retenté.
     */
    public function startBatch(): void
    {
        $this->aiClient->resetProviderAvailability();
    }

    public function analyze(Job $job, \DateTimeImmutable $now): AnalysisOutcome
    {
        $attemptsDone = $job->getAnalysisAttempts() + 1;
        $next = $this->retryPolicy->nextAttemptAt($attemptsDone, $now);

        try {
            $dto = $job->toDto();
            $analysis = $this->aiClient->analyze($dto->description, $dto->publishedAt);
            $usedFallback = $this->aiClient->lastAnalysisUsedFallback();
            ['score' => $score, 'breakdown' => $breakdown] = $this->scoring->compute($dto, $analysis);
        } catch (\Throwable $e) {
            $job->recordAnalysisFailure($e->getMessage(), $now, $next);

            return AnalysisOutcome::failed($e->getMessage());
        }

        $job->recordAnalysis($analysis, $score, $breakdown, $usedFallback, $now, $next);

        return AnalysisOutcome::analyzed($score, $usedFallback);
    }
}
