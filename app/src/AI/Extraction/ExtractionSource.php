<?php

declare(strict_types=1);

namespace App\AI\Extraction;

/**
 * Origine des exigences extraites : le LLM, ou l'heuristique locale de repli (moins précise : elle
 * ne distingue pas les compétences obligatoires des appréciées).
 */
enum ExtractionSource: string
{
    case Llm = 'llm';
    case Heuristic = 'heuristic';
}
