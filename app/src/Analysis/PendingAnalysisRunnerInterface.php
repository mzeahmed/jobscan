<?php

declare(strict_types=1);

namespace App\Analysis;

interface PendingAnalysisRunnerInterface
{
    /**
     * Analyse jusqu'à `$limit` offres persistées en attente ou à retenter, puis notifie
     * celles dont l'analyse est définitive.
     */
    public function run(int $limit): AnalysisSummary;

    /**
     * Ré-extrait les exigences des offres déjà analysées dont le schéma d'extraction est périmé.
     *
     * Ne notifie jamais, et ne remplace jamais une analyse LLM par un repli heuristique.
     */
    public function reextractOutdated(int $limit): AnalysisSummary;
}
