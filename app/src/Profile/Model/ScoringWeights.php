<?php

declare(strict_types=1);

namespace App\Profile\Model;

/**
 * Pondérations relatives des critères du score de compatibilité d'un profil de recherche.
 *
 * Ce sont des poids relatifs (≥ 0), pas des points : le score sera normalisé lors du calcul. Un
 * poids à 0 neutralise le critère. Les valeurs par défaut viennent de `app.profile.match_weights`.
 */
final readonly class ScoringWeights
{
    public const array CRITERIA = [
        'required_skills', 'nice_skills', 'title', 'location', 'remote',
        'contract', 'salary', 'seniority', 'languages', 'availability',
    ];

    /** @param array<string, int> $weights Poids indexés par critère (clés de `CRITERIA`) */
    private function __construct(public array $weights)
    {
    }

    /**
     * @param array<string, int> $defaults
     * @param array<string, int> $overrides
     */
    public static function create(array $defaults, array $overrides = []): self
    {
        $weights = [];
        foreach (self::CRITERIA as $criterion) {
            $weights[$criterion] = $overrides[$criterion] ?? $defaults[$criterion] ?? 0;
        }

        if (array_sum($weights) <= 0) {
            throw new \InvalidArgumentException('Au moins un poids de scoring doit être strictement positif.');
        }

        return new self($weights);
    }

    public function weight(string $criterion): int
    {
        return $this->weights[$criterion] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->weights);
    }
}
