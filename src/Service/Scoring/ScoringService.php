<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\DTO\JobDTO;

final class ScoringService
{
    /**
     * Score rapide sans IA, utilisé pour décider si l'analyse IA vaut le coup.
     */
    public function preScore(JobDTO $job): int
    {
        $score = 0;
        $text = strtolower($job->title . ' ' . $job->description);

        if (str_contains($text, 'php')) {
            $score += 10;
        }

        if (str_contains($text, 'symfony')) {
            $score += 15;
        }

        if (str_contains($text, 'wordpress')) {
            $score += 10;
        }

        if (
            str_contains($text, 'remote')
            || str_contains($text, 'télétravail')
            || str_contains($text, 'teletravail')
        ) {
            $score += 5;
        }

        if (str_contains($text, 'stage')) {
            $score -= 50;
        }

        if (str_contains($text, 'alternance')) {
            $score -= 50;
        }

        return $score;
    }

    /**
     * Score final sur 100, basé sur les données IA enrichies.
     *
     * @return array{score: int, breakdown: string[]}
     */
    public function compute(JobDTO $job, array $ai): array
    {
        $score = 0;
        $breakdown = [];
        $desc = strtolower($job->description);

        if (str_contains(strtolower($job->title), 'php')) {
            $score += 20;
            $breakdown[] = '+20 (PHP titre)';
        }

        $stack = array_map('strtolower', $ai['stack'] ?? []);

        if (\in_array('symfony', $stack, true)) {
            $score += 30;
            $breakdown[] = '+30 (Symfony stack)';
        }

        if (\in_array('wordpress', $stack, true)) {
            $score += 20;
            $breakdown[] = '+20 (WordPress stack)';
        }

        $contractType = $ai['contract_type'] ?? 'unknown';
        if ('freelance' === $contractType || ($ai['freelance'] ?? false)) {
            $score += 15;
            $breakdown[] = '+15 (freelance)';
        } elseif ('cdi' === $contractType) {
            $score += 10;
            $breakdown[] = '+10 (CDI)';
        }

        if ($ai['remote'] ?? false) {
            $score += 10;
            $breakdown[] = '+10 (remote)';
        }

        if ($ai['recent'] ?? false) {
            $score += 20;
            $breakdown[] = '+20 (offre récente)';
        }

        if (str_contains($desc, 'mission')) {
            $score += 10;
            $breakdown[] = '+10 (mission)';
        }

        if (str_contains($desc, 'urgent') || str_contains($desc, 'asap')) {
            $score += 15;
            $breakdown[] = '+15 (urgent/ASAP)';
        }

        if (str_contains($desc, 'stage')) {
            $score -= 50;
            $breakdown[] = '-50 (stage)';
        }

        if (str_contains($desc, 'alternance')) {
            $score -= 50;
            $breakdown[] = '-50 (alternance)';
        }

        return ['score' => max(0, min($score, 100)), 'breakdown' => $breakdown];
    }
}
