<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Données structurées extraites d'une offre d'emploi par `AIClient`
 * (analyse LLM ou fallback heuristique).
 */
final class AiAnalysisDto
{
    /**
     * @param  list<string>  $stack
     */
    public function __construct(
        public readonly array $stack,
        public readonly ContractType $contractType,
        public readonly bool $freelance,
        public readonly bool $remote,
        public readonly string $budget,
        public readonly bool $recent,
        public readonly Seniority $seniority,
    ) {
    }
}
