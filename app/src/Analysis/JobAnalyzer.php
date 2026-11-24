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
            $extraction = $this->aiClient->extract($dto->description, $dto->publishedAt);
            ['score' => $score, 'breakdown' => $breakdown] = $this->scoring->compute($dto, $extraction->analysis);
        } catch (\Throwable $e) {
            $job->recordAnalysisFailure($e->getMessage(), $now, $next);

            return AnalysisOutcome::failed($e->getMessage());
        }

        $job->recordAnalysis($extraction->analysis, $score, $breakdown, $extraction->usedFallback, $now, $next);
        $job->setRequirements($extraction->requirements);

        return AnalysisOutcome::analyzed($score, $extraction->usedFallback);
    }

    /**
     * Ré-extrait les exigences d'une offre déjà analysée par le LLM, avec le schéma courant.
     *
     * N'écrase **jamais** une analyse LLM par un repli heuristique : si le LLM est indisponible ou renvoie
     * une réponse inexploitable, l'offre reste inchangée (et sera retentée à la prochaine demande).
     */
    public function reextract(Job $job, \DateTimeImmutable $now): AnalysisOutcome
    {
        try {
            $dto = $job->toDto();
            $extraction = $this->aiClient->extract($dto->description, $dto->publishedAt);

            if ($extraction->usedFallback) {
                return AnalysisOutcome::analyzed($job->getScore() ?? 0, true);
            }

            ['score' => $score, 'breakdown' => $breakdown] = $this->scoring->compute($dto, $extraction->analysis);
        } catch (\Throwable $e) {
            return AnalysisOutcome::failed($e->getMessage());
        }

        $job->applyReextraction($extraction->analysis, $score, $breakdown, $extraction->requirements, $now);

        return AnalysisOutcome::analyzed($score, false);
    }
}
