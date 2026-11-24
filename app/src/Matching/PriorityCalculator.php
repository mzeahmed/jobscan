<?php

declare(strict_types=1);

namespace App\Matching;

/**
 * Score de priorité : combine la compatibilité et la fraîcheur (par défaut 75 % / 25 %, configurable).
 *
 * Garde-fou : la fraîcheur ne peut pas rendre prioritaire une offre qui ne l'est pas déjà par sa
 * compatibilité. Une offre éliminée a une priorité nulle ; une offre sous le seuil ou aux données
 * insuffisantes ne dépasse jamais son score de compatibilité. Une offre toute fraîche mais incompatible
 * reste donc au rang de son (faible) score de compatibilité.
 */
final readonly class PriorityCalculator
{
    /**
     * @param int $matchWeight Poids de la compatibilité (config `app.profile.priority_weights.match`)
     * @param int $freshnessWeight Poids de la fraîcheur (config `app.profile.priority_weights.freshness`)
     */
    public function __construct(
        private int $matchWeight = 75,
        private int $freshnessWeight = 25,
    ) {
        if ($matchWeight < 0 || $freshnessWeight < 0 || $matchWeight + $freshnessWeight <= 0) {
            throw new \InvalidArgumentException('Les poids de priorité doivent être positifs, avec une somme strictement positive.');
        }
    }

    /** @return array{match: int, freshness: int} */
    public function weights(): array
    {
        return ['match' => $this->matchWeight, 'freshness' => $this->freshnessWeight];
    }

    public function priority(MatchResult $result, int $freshnessScore): int
    {
        if ($result->verdict === MatchVerdict::Eliminated) {
            return 0;
        }

        $combined = (int) round(
            ($this->matchWeight * $result->matchScore + $this->freshnessWeight * $freshnessScore) / ($this->matchWeight + $this->freshnessWeight),
        );

        return $result->isEligible() ? $combined : min($combined, $result->matchScore);
    }
}
