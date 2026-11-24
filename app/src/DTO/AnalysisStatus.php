<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * État de l'analyse d'une offre persistée.
 *
 * - `Pending`  : jamais analysée ;
 * - `Done`     : analysée par le LLM ;
 * - `Fallback` : analysée par l'heuristique locale (LLM indisponible), à retenter ;
 * - `Failed`   : l'analyse a levé une erreur, à retenter.
 */
enum AnalysisStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Fallback = 'fallback';
    case Failed = 'failed';
}
