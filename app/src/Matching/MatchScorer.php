<?php

declare(strict_types=1);

namespace App\Matching;

use App\Profile\Model\SearchProfile;
use App\Profile\Model\CandidateProfile;

/**
 * Score de compatibilité d'une offre avec un profil de recherche : déterministe, explicable, testable.
 *
 * Le LLM n'intervient qu'en amont (extraction des exigences) : tout le calcul est en PHP, à partir des
 * exigences extraites et du profil déclaré.
 *
 * 1. **Éligibilité** (`EligibilityChecker`) : les critères éliminatoires, indépendants du score.
 * 2. **Critères** (`CriteriaEvaluator`) : chacun évalué entre 0 et 1, avec son explication.
 * 3. **Score** : moyenne pondérée (poids du profil de recherche) des **seuls critères connus**. Un critère
 *    inconnu ou sans objet sort du calcul : une information manquante ne fait pas baisser le score.
 * 4. **Couverture** : part du poids des critères applicables qui a pu être évaluée. Une couverture faible
 *    signale que le score repose sur peu d'informations (verdict « données insuffisantes »).
 */
final readonly class MatchScorer
{
    /** À incrémenter à chaque changement de règle de calcul : les résultats stockés sont alors recalculés. */
    public const int RULES_VERSION = 1;

    private SkillComparator $comparator;
    private EligibilityChecker $eligibility;
    private CriteriaEvaluator $criteria;
    private ArgumentBuilder $arguments;

    /**
     * @param list<list<string>> $skillAliases Groupes de compétences équivalentes (config `app.profile.skill_aliases`)
     * @param array<string, int|float> $seniorityYears Années attendues par niveau (config `app.profile.seniority_years`)
     * @param int $minCoverage Couverture minimale (0–100) pour conclure (config `app.profile.match_min_coverage`)
     */
    public function __construct(
        private array $skillAliases = [],
        private array $seniorityYears = ['junior' => 0, 'mid' => 2, 'senior' => 5],
        private int $minCoverage = 40,
    ) {
        $skills = new SkillCanonicalizer($skillAliases);
        $this->comparator = new SkillComparator($skills);
        $this->eligibility = new EligibilityChecker($skills);
        $this->criteria = new CriteriaEvaluator($seniorityYears);
        $this->arguments = new ArgumentBuilder($skills);
    }

    /**
     * Ce qui, dans la configuration de calcul, change le résultat : intégré à l'empreinte des résultats stockés.
     *
     * @return array<string, mixed>
     */
    public function signature(): array
    {
        return [
            'rules_version' => self::RULES_VERSION,
            'skill_aliases' => $this->skillAliases,
            'seniority_years' => $this->seniorityYears,
            'min_coverage' => $this->minCoverage,
        ];
    }

    public function match(CandidateProfile $candidate, SearchProfile $profile, JobFacts $facts, \DateTimeImmutable $now): MatchResult
    {
        $preferences = MatchPreferences::resolve($candidate, $profile);
        $comparison = $this->comparator->compare($candidate, $facts->requirements);
        $eliminations = $this->eligibility->check($preferences, $profile, $facts);
        $criteria = $this->criteria->evaluate($candidate, $profile, $preferences, $facts, $comparison, $now);

        $known = $applicable = $points = 0.0;
        foreach ($criteria as $criterion) {
            if ($criterion->status->isKnown()) {
                $known += $criterion->weight;
                $points += $criterion->weight * ($criterion->score ?? 0.0);
            }

            if ($criterion->status->isKnown() || $criterion->status === CriterionStatus::Unknown) {
                $applicable += $criterion->weight;
            }
        }

        $matchScore = $known > 0 ? (int) round(100 * $points / $known) : 0;
        $coverage = $applicable > 0 ? (int) round(100 * $known / $applicable) : 0;

        return new MatchResult(
            searchProfileId: $profile->id,
            verdict: match (true) {
                $eliminations !== [] => MatchVerdict::Eliminated,
                $coverage < $this->minCoverage => MatchVerdict::InsufficientData,
                $matchScore < $profile->minMatchScore => MatchVerdict::BelowThreshold,
                default => MatchVerdict::Eligible,
            },
            matchScore: $matchScore,
            coverage: $coverage,
            criteria: $criteria,
            matchedSkills: $comparison->matchedSkills(),
            missingRequiredSkills: $comparison->missingRequired,
            missingNiceSkills: $comparison->missingNice,
            eliminations: $eliminations,
            unknown: array_values(array_map(
                static fn (CriterionResult $criterion): string => $criterion->detail,
                array_filter($criteria, static fn (CriterionResult $criterion): bool => $criterion->status === CriterionStatus::Unknown && $criterion->weight > 0),
            )),
            arguments: $this->arguments->build($candidate, $facts, $comparison, $criteria),
            cvId: $profile->cvId,
            requirementsSource: $facts->requirements->source,
        );
    }
}
