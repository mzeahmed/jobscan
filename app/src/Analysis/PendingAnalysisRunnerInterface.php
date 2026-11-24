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
}
