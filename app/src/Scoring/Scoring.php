<?php

declare(strict_types=1);

namespace App\Scoring;

use App\DTO\JobDto;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;

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
final readonly class Scoring
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
     *         seniority_bonuses: array<string, int>,
     *         budget_bonus: array{min_daily_rate: int, min_annual_salary: int, points: int},
     *     },
     * } $scoringConfig Configuration issue de `app.scoring` dans `jobscan.yaml`
     */
    public function __construct(
        private array $scoringConfig,
    ) {
    }

    /**
     * Calcule un score rapide sans IA pour décider si l'analyse LLM vaut le coût.
     *
     * Additionne les points des mots-clés trouvés dans le texte brut (titre + description),
     * applique un bonus remote et soustrait les points des mots-clés négatifs.
     * Le résultat n'est pas borné — il peut être négatif si des mots-clés négatifs dominent.
     */
    public function preScore(JobDto $job): int
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
     *   - Bonus de séniorité (`app.scoring.compute.seniority_bonuses`)
     *   - Bonus de budget si le TJM ou le salaire annuel dépasse un seuil configuré
     *
     * Le score est clampé entre 0 et 100. Le breakdown liste chaque contribution
     * sous la forme `+N (critère)` pour faciliter le débogage.
     *
     * @return array{score: int, breakdown: string[]}
     */
    public function compute(JobDto $job, AiAnalysisDto $ai): array
    {
        $score = 0;
        $breakdown = [];
        $config = $this->scoringConfig['compute'];
        $title = strtolower($job->title);
        $desc = strtolower($job->description);
        $stack = array_map(strtolower(...), $ai->stack);

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

        if (ContractType::Freelance === $ai->contractType || $ai->freelance) {
            $points = $config['contract_bonuses']['freelance'];
            $score += $points;
            $breakdown[] = sprintf('%+d (freelance)', $points);
        } elseif (ContractType::Cdi === $ai->contractType) {
            $points = $config['contract_bonuses']['cdi'];
            $score += $points;
            $breakdown[] = sprintf('%+d (CDI)', $points);
        }

        $flags = ['remote' => $ai->remote, 'recent' => $ai->recent];
        foreach ($config['flag_bonuses'] as $flag => $points) {
            if ($flags[$flag] ?? false) {
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

        $seniorityPoints = $config['seniority_bonuses'][$ai->seniority->value] ?? 0;
        if (0 !== $seniorityPoints) {
            $score += $seniorityPoints;
            $breakdown[] = sprintf('%+d (séniorité %s)', $seniorityPoints, $ai->seniority->value);
        }

        $budgetBonus = $this->budgetBonus($ai->budget, $config['budget_bonus']);
        if ($budgetBonus !== null) {
            $score += $budgetBonus['points'];
            $breakdown[] = sprintf('%+d (%s)', $budgetBonus['points'], $budgetBonus['label']);
        }

        return ['score' => max(0, min($score, 100)), 'breakdown' => $breakdown];
    }

    /**
     * Calcule un bonus si le budget annoncé dépasse un seuil configuré.
     *
     * Reconnaît le même format de sortie que `AIClient::extractBudget()` :
     * TJM journalier (`500€/j`) ou salaire annuel (`45k€/an`, `60-80k€/an`,
     * la borne haute étant retenue pour les fourchettes).
     *
     * @param  array{min_daily_rate: int, min_annual_salary: int, points: int}  $config
     * @return array{points: int, label: string}|null
     */
    private function budgetBonus(string $budget, array $config): ?array
    {
        if (preg_match('/(\d{3,4})\s*€?\s*\/?\s*j/iu', $budget, $matches)
            && (int) $matches[1] >= $config['min_daily_rate']
        ) {
            return ['points' => $config['points'], 'label' => 'budget TJM'];
        }

        if (preg_match('/(\d{2,3})(?:\s*[-–]\s*(\d{2,3}))?\s*k/iu', $budget, $matches)) {
            $salary = isset($matches[2]) ? (int) $matches[2] : (int) $matches[1];

            if ($salary >= $config['min_annual_salary']) {
                return ['points' => $config['points'], 'label' => 'budget annuel'];
            }
        }

        return null;
    }
}
