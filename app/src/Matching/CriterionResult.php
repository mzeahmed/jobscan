<?php

declare(strict_types=1);

namespace App\Matching;

/**
 * Évaluation d'un critère du score de compatibilité, avec son explication.
 */
final readonly class CriterionResult
{
    /**
     * @param string $criterion Clé de `ScoringWeights::CRITERIA`
     * @param float|null $score Satisfaction entre 0 et 1 ; null si le critère n'est pas évalué
     * @param int $weight Poids relatif du critère dans ce profil de recherche
     */
    public function __construct(
        public string $criterion,
        public CriterionStatus $status,
        public ?float $score,
        public int $weight,
        public string $detail,
    ) {
    }

    /** @return array{criterion: string, status: string, score: ?float, weight: int, detail: string} */
    public function toArray(): array
    {
        return [
            'criterion' => $this->criterion,
            'status' => $this->status->value,
            'score' => $this->score,
            'weight' => $this->weight,
            'detail' => $this->detail,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $status = CriterionStatus::tryFrom((string) ($data['status'] ?? ''));
        if (!is_string($data['criterion'] ?? null) || $status === null) {
            return null;
        }

        return new self(
            $data['criterion'],
            $status,
            is_int($data['score'] ?? null) || is_float($data['score'] ?? null) ? (float) $data['score'] : null,
            is_int($data['weight'] ?? null) ? $data['weight'] : 0,
            is_string($data['detail'] ?? null) ? $data['detail'] : '',
        );
    }
}
