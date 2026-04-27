<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\DTO\JobDTO;

final class ScoringService
{
    /**
     * @param array{
     *     prescore: array{
     *         keywords: array<string, int>,
     *         remote_keywords: list<string>,
     *         remote_bonus: int,
     *         negative_keywords: array<string, int>,
     *     },
     *     compute: array{
     *         title_keywords: array<string, int>,
     *         stack_keywords: array<string, int>,
     *         contract_bonuses: array<string, int>,
     *         flag_bonuses: array<string, int>,
     *         description_keywords: array<string, int>,
     *         negative_keywords: array<string, int>,
     *     },
     * } $scoringConfig
     */
    public function __construct(
        private readonly array $scoringConfig,
    ) {
    }

    /**
     * Score rapide sans IA, utilisé pour décider si l'analyse IA vaut le coup.
     */
    public function preScore(JobDTO $job): int
    {
        $score = 0;
        $config = $this->scoringConfig['prescore'];
        $text = strtolower($job->title . ' ' . $job->description);

        foreach ($config['keywords'] as $keyword => $points) {
            if (str_contains($text, $keyword)) {
                $score += $points;
            }
        }

        foreach ($config['remote_keywords'] as $keyword) {
            if (str_contains($text, $keyword)) {
                $score += $config['remote_bonus'];
                break;
            }
        }

        foreach ($config['negative_keywords'] as $keyword => $points) {
            if (str_contains($text, $keyword)) {
                $score += $points;
            }
        }

        return $score;
    }

    /**
     * Score final sur 100, basé sur les données IA enrichies.
     *
     * @param array{stack: list<string>, contract_type: string, freelance: bool, remote: bool, budget: string, recent: bool, seniority: string} $ai
     *
     * @return array{score: int, breakdown: string[]}
     */
    public function compute(JobDTO $job, array $ai): array
    {
        $score = 0;
        $breakdown = [];
        $config = $this->scoringConfig['compute'];
        $title = strtolower($job->title);
        $desc = strtolower($job->description);
        $stack = array_map('strtolower', $ai['stack']);

        foreach ($config['title_keywords'] as $keyword => $points) {
            if (str_contains($title, $keyword)) {
                $score += $points;
                $breakdown[] = sprintf('%+d (%s titre)', $points, $keyword);
            }
        }

        foreach ($config['stack_keywords'] as $keyword => $points) {
            if (in_array($keyword, $stack, true)) {
                $score += $points;
                $breakdown[] = sprintf('%+d (%s stack)', $points, $keyword);
            }
        }

        $contractType = $ai['contract_type'];
        if ('freelance' === $contractType || $ai['freelance']) {
            $points = $config['contract_bonuses']['freelance'];
            $score += $points;
            $breakdown[] = sprintf('%+d (freelance)', $points);
        } elseif ('cdi' === $contractType) {
            $points = $config['contract_bonuses']['cdi'];
            $score += $points;
            $breakdown[] = sprintf('%+d (CDI)', $points);
        }

        foreach ($config['flag_bonuses'] as $flag => $points) {
            if ($ai[$flag] ?? false) {
                $score += $points;
                $breakdown[] = sprintf('%+d (%s)', $points, $flag);
            }
        }

        foreach ($config['description_keywords'] as $keyword => $points) {
            if (str_contains($desc, $keyword)) {
                $score += $points;
                $breakdown[] = sprintf('%+d (%s)', $points, $keyword);
            }
        }

        foreach ($config['negative_keywords'] as $keyword => $points) {
            if (str_contains($desc, $keyword)) {
                $score += $points;
                $breakdown[] = sprintf('%+d (%s)', $points, $keyword);
            }
        }

        return ['score' => max(0, min($score, 100)), 'breakdown' => $breakdown];
    }
}
