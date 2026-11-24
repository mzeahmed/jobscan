<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * Parcours des candidatures **envoyées** : combien ont reçu une réponse, un entretien, une proposition.
 *
 * « Atteint » se lit dans l'historique : une candidature refusée après un entretien a bien atteint l'entretien.
 *
 * @param int $sent Candidatures envoyées (date d'envoi renseignée)
 * @param int $responded Ayant reçu une réponse (entretien, test, proposition ou refus)
 * @param int $interviews Ayant atteint un entretien ou un test technique
 * @param int $offers Ayant atteint une proposition
 * @param int $accepted Acceptées
 * @param int $rejected Actuellement refusées
 * @param int $noResponse Actuellement « sans réponse »
 * @param float|null $medianDaysToResponse Délai médian entre l'envoi et la première réponse, `null` sans réponse
 */
final readonly class Funnel
{
    public function __construct(
        public int $sent,
        public int $responded,
        public int $interviews,
        public int $offers,
        public int $accepted,
        public int $rejected,
        public int $noResponse,
        public ?float $medianDaysToResponse,
    ) {
    }
}
