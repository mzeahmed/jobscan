<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\DTO\JobDTO;

final class ScoringService
{
    /**
     * Calcule un score de pertinence sur 100.
     *
     * Règles :
     *   PHP dans le titre        → +20
     *   Symfony dans la stack    → +30
     *   WordPress dans la stack  → +20
     *   Remote                   → +10
     *   Offre récente            → +20
     */
    public function compute(JobDTO $job, array $ai): int
    {
        $score = 0;

        if (str_contains(strtolower($job->title), 'php')) {
            $score += 20;
        }

        $stack = array_map('strtolower', $ai['stack'] ?? []);
        if (in_array('symfony', $stack, true)) {
            $score += 30;
        }

        if ($ai['wordpress'] ?? false) {
            $score += 20;
        }

        if ($ai['remote'] ?? false) {
            $score += 10;
        }

        if ($ai['recent'] ?? false) {
            $score += 20;
        }

        $description = strtolower($job->description);

        // BONUS pertinence
        if (str_contains($description, 'senior')) {
            $score += 10;
        }

        if (str_contains($description, 'mission')) {
            $score += 10;
        }

        // BONUS opportunité rapide
        if (str_contains($description, 'urgent')) {
            $score += 15;
        }

        if (str_contains($description, 'asap')) {
            $score += 15;
        }

        // MALUS (très important)
        if (str_contains($description, 'stage')) {
            $score -= 50;
        }

        if (str_contains($description, 'alternance')) {
            $score -= 50;
        }

        if (str_contains($description, 'junior')) {
            $score -= 20;
        }

        return min($score, 100);
    }
}
