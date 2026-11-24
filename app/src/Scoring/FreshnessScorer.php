<?php

declare(strict_types=1);

namespace App\Scoring;

use App\DTO\PublicationDateConfidence;

/**
 * Score de fraîcheur (0–100) d'une offre, selon l'ancienneté de sa date de publication.
 *
 * Le barème (`app.profile.freshness`) est une hypothèse métier configurable. La date
 * de **publication** seule compte : la date de première détection par Jobscan ne
 * prouve rien sur l'âge réel d'une offre.
 *
 * Une date inconnue, de confiance inconnue ou située dans le futur reçoit le score
 * `unknown_score` (jamais le maximum). Une date de confiance faible est plafonnée
 * par `low_confidence_cap`.
 */
final readonly class FreshnessScorer
{
    /** Tolérance sur les horloges désynchronisées avant de juger une date « dans le futur ». */
    private const int FUTURE_TOLERANCE_SECONDS = 3600;

    /**
     * @param list<array{max_hours: int|float, score: int}> $bands Tranches triées par âge croissant (borne supérieure exclue)
     * @param int $olderScore Score au-delà de la dernière tranche
     * @param int $unknownScore Score d'une date inconnue ou invraisemblable
     * @param int $lowConfidenceCap Plafond appliqué aux dates de confiance faible
     */
    public function __construct(
        private array $bands,
        private int $olderScore = 5,
        private int $unknownScore = 25,
        private int $lowConfidenceCap = 50,
    ) {
        $previous = 0;
        foreach ($bands as $band) {
            if ($band['max_hours'] <= $previous) {
                throw new \InvalidArgumentException('Les tranches de fraîcheur doivent être triées par âge croissant.');
            }
            $previous = $band['max_hours'];
        }
    }

    public function score(
        ?\DateTimeImmutable $publishedAt,
        PublicationDateConfidence $confidence,
        \DateTimeImmutable $now = new \DateTimeImmutable(),
    ): int {
        if ($publishedAt === null || $confidence === PublicationDateConfidence::Unknown) {
            return $this->unknownScore;
        }

        $seconds = $now->getTimestamp() - $publishedAt->getTimestamp();
        if ($seconds < -self::FUTURE_TOLERANCE_SECONDS) {
            return $this->unknownScore;
        }

        $score = $this->scoreForAge(max(0, $seconds) / 3600);

        return $confidence === PublicationDateConfidence::Low ? min($score, $this->lowConfidenceCap) : $score;
    }

    private function scoreForAge(float $ageHours): int
    {
        foreach ($this->bands as $band) {
            if ($ageHours < $band['max_hours']) {
                return $band['score'];
            }
        }

        return $this->olderScore;
    }
}
