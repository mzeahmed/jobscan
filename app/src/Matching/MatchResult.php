<?php

declare(strict_types=1);

namespace App\Matching;

use App\AI\Extraction\ExtractionSource;

/**
 * Résultat explicable du matching d'une offre avec un profil de recherche : verdict, score de
 * compatibilité, couverture de l'information, détail par critère, compétences couvertes ou manquantes,
 * exigences éliminatoires, informations inconnues et arguments à mettre en avant.
 *
 * Tout est dérivé de l'extraction de l'annonce et du profil déclaré : aucune information n'est inventée.
 */
final readonly class MatchResult
{
    /**
     * @param int $matchScore Compatibilité, 0–100, calculée sur les seuls critères connus
     * @param int $coverage Part (0–100) du poids des critères applicables qui a pu être évaluée
     * @param list<CriterionResult> $criteria
     * @param list<string> $matchedSkills Compétences de l'annonce (exigées ou appréciées) que le candidat déclare
     * @param list<string> $missingRequiredSkills Compétences exigées que le candidat ne déclare pas
     * @param list<string> $missingNiceSkills Compétences appréciées que le candidat ne déclare pas
     * @param list<string> $eliminations Raisons éliminatoires (vide si l'offre n'est pas éliminée)
     * @param list<string> $unknown Informations qui manquent pour évaluer certains critères
     * @param list<string> $arguments Faits du profil à mettre en avant pour cette offre
     * @param string|null $cvId Version de CV recommandée
     */
    public function __construct(
        public string $searchProfileId,
        public MatchVerdict $verdict,
        public int $matchScore,
        public int $coverage,
        public array $criteria,
        public array $matchedSkills,
        public array $missingRequiredSkills,
        public array $missingNiceSkills,
        public array $eliminations,
        public array $unknown,
        public array $arguments,
        public ?string $cvId,
        public ExtractionSource $requirementsSource,
    ) {
    }

    public function isEligible(): bool
    {
        return $this->verdict === MatchVerdict::Eligible;
    }

    public function criterion(string $name): ?CriterionResult
    {
        return array_find($this->criteria, static fn (CriterionResult $criterion): bool => $criterion->criterion === $name);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'search_profile_id' => $this->searchProfileId,
            'verdict' => $this->verdict->value,
            'match_score' => $this->matchScore,
            'coverage' => $this->coverage,
            'criteria' => array_map(static fn (CriterionResult $criterion): array => $criterion->toArray(), $this->criteria),
            'matched_skills' => $this->matchedSkills,
            'missing_required_skills' => $this->missingRequiredSkills,
            'missing_nice_skills' => $this->missingNiceSkills,
            'eliminations' => $this->eliminations,
            'unknown' => $this->unknown,
            'arguments' => $this->arguments,
            'cv_id' => $this->cvId,
            'requirements_source' => $this->requirementsSource->value,
        ];
    }

    /**
     * Relit un résultat stocké ; `null` si les données sont inexploitables.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $verdict = MatchVerdict::tryFrom((string) ($data['verdict'] ?? ''));
        if ($verdict === null || !is_string($data['search_profile_id'] ?? null)) {
            return null;
        }

        $criteria = [];
        foreach (is_array($data['criteria'] ?? null) ? $data['criteria'] : [] as $item) {
            $criterion = is_array($item) ? CriterionResult::fromArray($item) : null;
            if ($criterion !== null) {
                $criteria[] = $criterion;
            }
        }

        return new self(
            $data['search_profile_id'],
            $verdict,
            is_int($data['match_score'] ?? null) ? $data['match_score'] : 0,
            is_int($data['coverage'] ?? null) ? $data['coverage'] : 0,
            $criteria,
            self::strings($data['matched_skills'] ?? null),
            self::strings($data['missing_required_skills'] ?? null),
            self::strings($data['missing_nice_skills'] ?? null),
            self::strings($data['eliminations'] ?? null),
            self::strings($data['unknown'] ?? null),
            self::strings($data['arguments'] ?? null),
            is_string($data['cv_id'] ?? null) ? $data['cv_id'] : null,
            ExtractionSource::tryFrom((string) ($data['requirements_source'] ?? '')) ?? ExtractionSource::Llm,
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
