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
 * sous la clé `app.profile.scoring` — aucune valeur n'est codée en dur dans cette classe.
 */
final readonly class Scoring
{
    /** @var array<string, mixed> */
    private array $scoringConfig;

    /**
     * @param array{
     *     prescore: array{
     *         keywords: array<array-key, int|string>,
     *         remote_keywords: list<string>,
     *         remote_bonus: int,
     *         negative_keywords: array<array-key, int|string>,
     *     },
     *     compute: array{
     *         title_keywords: array<array-key, int|string>,
     *         stack_keywords: array<array-key, int|string>,
     *         contract_bonuses: array<string, int>,
     *         flag_bonuses: array<string, int>,
     *         description_keywords: array<array-key, int|string>,
     *         negative_keywords: array<array-key, int|string>,
     *         seniority_bonuses: array<string, int>,
     *         budget_bonus: array{min_daily_rate: int, min_annual_salary: int, points: int},
     *         relevance?: array{max_points?: int, target_languages?: list<string>, off_target_penalty?: int, location_penalty?: int},
     *     },
     * } $scoringConfig Configuration issue de `app.profile.scoring` dans `jobscan.yaml`. Les tables de
     *   pondération acceptent `mot-clé => points` ou une liste `"mot:points"` (variables d'environnement).
     */
    public function __construct(array $scoringConfig)
    {
        $this->scoringConfig = self::normalizeConfig($scoringConfig);
    }

    /**
     * Convertit les tables de pondération issues de l'environnement (`['symfony:30', 'stage:-50']`)
     * en tableaux `mot-clé => points`. Les tables déjà au format `mot-clé => points` sont conservées.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function normalizeConfig(array $config): array
    {
        $tables = [
            'prescore' => ['keywords', 'negative_keywords'],
            'compute' => ['title_keywords', 'stack_keywords', 'description_keywords', 'negative_keywords'],
        ];

        foreach ($tables as $section => $keys) {
            foreach ($keys as $key) {
                if (isset($config[$section][$key]) && \is_array($config[$section][$key])) {
                    $config[$section][$key] = self::parseWeights($config[$section][$key], $section . '.' . $key);
                }
            }
        }

        return $config;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, int>
     */
    private static function parseWeights(array $raw, string $name): array
    {
        $weights = [];

        foreach ($raw as $key => $value) {
            if (\is_string($key)) {
                $weights[$key] = (int) $value;
                continue;
            }

            $entry = trim((string) $value);
            if ($entry === '') {
                continue; // CSV vide issu de l'env
            }

            $separator = strrpos($entry, ':');
            $points = $separator === false ? '' : trim(substr($entry, $separator + 1));
            $keyword = $separator === false ? '' : trim(substr($entry, 0, $separator));

            if ($keyword === '' || 1 !== preg_match('/^[+-]?\d+$/', $points)) {
                throw new \InvalidArgumentException(sprintf(
                    'Pondération invalide "%s" dans %s : format attendu "mot:points" (ex. "symfony:30").',
                    $entry,
                    $name,
                ));
            }

            $weights[$keyword] = (int) $points;
        }

        return $weights;
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
        $text = TextMatcher::normalize($job->title . ' ' . $job->description);

        foreach ($config['keywords'] as $keyword => $points) {
            if (TextMatcher::containsNormalized($text, (string) $keyword)) {
                $score += $points;
            }
        }

        foreach ($config['remote_keywords'] as $keyword) {
            if (TextMatcher::containsNormalized($text, (string) $keyword)) {
                $score += $config['remote_bonus'];
                break;
            }
        }

        foreach ($config['negative_keywords'] as $keyword => $points) {
            if (TextMatcher::containsNormalized($text, (string) $keyword)) {
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
     *   - Bonus de séniorité (`app.profile.scoring.compute.seniority_bonuses`)
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
        $title = TextMatcher::normalize($job->title);
        $desc = TextMatcher::normalize($job->description);
        $stack = array_map(strtolower(...), $ai->stack);

        foreach ($config['title_keywords'] as $keyword => $points) {
            if (TextMatcher::containsNormalized($title, (string) $keyword)) {
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
            if (TextMatcher::containsNormalized($desc, (string) $keyword)) {
                $score += $points;
                $breakdown[] = sprintf('%+d (%s)', $points, $keyword);
            }
        }

        foreach ($config['negative_keywords'] as $keyword => $points) {
            if (TextMatcher::containsNormalized($desc, (string) $keyword)) {
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

        foreach ($this->relevanceAdjustments($ai, $config['relevance'] ?? []) as $adjustment) {
            $score += $adjustment['points'];
            $breakdown[] = sprintf('%+d (%s)', $adjustment['points'], $adjustment['label']);
        }

        return ['score' => max(0, min($score, 100)), 'breakdown' => $breakdown];
    }

    /**
     * Ajustements issus du jugement de pertinence du LLM (champs optionnels : absents
     * du fallback heuristique, auquel cas aucun ajustement n'est appliqué).
     *
     * - `relevance` (0-100) : bonus/malus linéaire autour de 50, borné à ±`max_points`
     * - `primary_language` hors `target_languages` : malus `off_target_penalty`
     * - `location_ok` = false : malus `location_penalty`
     *
     * @param  array{max_points?: int, target_languages?: list<string>, off_target_penalty?: int, location_penalty?: int}  $config
     * @return list<array{points: int, label: string}>
     */
    private function relevanceAdjustments(AiAnalysisDto $ai, array $config): array
    {
        $adjustments = [];

        $maxPoints = $config['max_points'] ?? 0;
        if ($ai->relevance !== null && $maxPoints > 0) {
            $points = (int) round(($ai->relevance - 50) / 50 * $maxPoints);
            if (0 !== $points) {
                $adjustments[] = ['points' => $points, 'label' => sprintf('pertinence IA %d', $ai->relevance)];
            }
        }

        $targets = array_values(array_filter(
            array_map(static fn (string $language): string => TextMatcher::normalize(trim($language)), $config['target_languages'] ?? []),
            static fn (string $language): bool => $language !== '',
        ));
        $language = $ai->primaryLanguage;
        $penalty = $config['off_target_penalty'] ?? 0;
        if ($language !== null && $targets !== [] && 0 !== $penalty
            && !in_array($language, ['', 'unknown', 'none'], true)
            && !in_array(TextMatcher::normalize($language), $targets, true)
        ) {
            $adjustments[] = ['points' => $penalty, 'label' => sprintf('langage principal %s hors cible', $language)];
        }

        $locationPenalty = $config['location_penalty'] ?? 0;
        if (false === $ai->locationOk && 0 !== $locationPenalty) {
            $adjustments[] = ['points' => $locationPenalty, 'label' => 'localisation hors cible'];
        }

        return $adjustments;
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
