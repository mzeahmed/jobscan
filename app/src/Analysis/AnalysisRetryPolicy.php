<?php

declare(strict_types=1);

namespace App\Analysis;

/**
 * Politique de reprise des analyses : backoff exponentiel plafonné, nombre de
 * tentatives borné. Au-delà, plus aucune tentative n'est planifiée.
 */
final readonly class AnalysisRetryPolicy
{
    public function __construct(
        private int $maxAttempts = 5,
        private int $initialDelayMinutes = 15,
        private float $multiplier = 2.0,
        private int $maxDelayMinutes = 1440,
    ) {
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * @param int $attemptsDone Nombre de tentatives déjà effectuées, y compris celle qui vient de se terminer
     */
    public function nextAttemptAt(int $attemptsDone, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($attemptsDone >= $this->maxAttempts) {
            return null;
        }

        $delay = (int) min(
            $this->maxDelayMinutes,
            $this->initialDelayMinutes * ($this->multiplier ** max(0, $attemptsDone - 1)),
        );

        return $now->modify(sprintf('+%d minutes', max(1, $delay)));
    }
}
