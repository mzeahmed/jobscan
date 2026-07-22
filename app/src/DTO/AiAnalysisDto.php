<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Données structurées extraites d'une offre d'emploi par `AIClient`
 * (analyse LLM ou fallback heuristique).
 */
final readonly class AiAnalysisDto
{
    /**
     * @param  list<string>  $stack
     */
    public function __construct(
        public array $stack,
        public ContractType $contractType,
        public bool $freelance,
        public bool $remote,
        public string $budget,
        public bool $recent,
        public Seniority $seniority,
    ) {
    }
}
