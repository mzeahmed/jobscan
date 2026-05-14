<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\DTO\JobDTO;

/**
 * Calcule le score de pertinence d'une offre d'emploi en deux passes.
 *
 * **Passe 1 — pré-score heuristique (`preScore`)**
 * Rapide, sans appel IA. Évalue la présence de mots-clés et de signaux remote
 * directement dans le texte brut. Utilisée par `JobProcessor` pour filtrer les
 * offres trop peu pertinentes avant de solliciter le LLM.
 *
 * **Passe 2 — score final (`compute`)**
 * Enrichie par les données extraites par l'IA (stack, type de contrat, remote,
 * récence, séniorité). Produit un score entre 0 et 100 et un breakdown lisible
 * des critères ayant contribué.
 *
 * Toute la configuration est externalisée dans `config/packages/jobscan.yaml`
 * sous la clé `app.scoring` — aucune valeur n'est codée en dur dans cette classe.
 */
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
     * } $scoringConfig Configuration issue de `app.scoring` dans `jobscan.yaml`
     */
    public function __construct(
        private readonly array $scoringConfig,
    ) {
    }

    /**
     * Calcule un score rapide sans IA pour décider si l'analyse LLM vaut le coût.
     *
     * Additionne les points des mots-clés trouvés dans le texte brut (titre + description),
     * applique un bonus remote et soustrait les points des mots-clés négatifs.
     * Le résultat n'est pas borné — il peut être négatif si des mots-clés négatifs dominent.
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
     * Calcule le score final (0–100) à partir des données enrichies par l'IA.
     *
     * Critères évalués dans l'ordre :
     *   - Mots-clés dans le titre
     *   - Stack technique extraite par l'IA
     *   - Type de contrat (freelance prioritaire, CDI en fallback)
     *   - Flags booléens IA (remote, recent)
     *   - Mots-clés dans la description (mission, urgent, asap…)
     *   - Mots-clés négatifs (stage, alternance…)
     *
     * Le score est clampé entre 0 et 100. Le breakdown liste chaque contribution
     * sous la forme `+N (critère)` pour faciliter le débogage.
     *
     * @param array{stack: list<string>, contract_type: string, freelance: bool, remote: bool, budget: string, recent: bool, seniority: string} $ai Données extraites par `AIClient`
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
