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
     * @param  string|null  $primaryLanguage  Langage/techno principale du poste (jugement LLM, null si inconnu)
     * @param  int|null  $relevance  Pertinence 0-100 par rapport au profil recherché (jugement LLM, null si absent)
     * @param  bool|null  $locationOk  Localisation compatible avec le profil (jugement LLM, null si inconnu)
     */
    public function __construct(
        public array $stack,
        public ContractType $contractType,
        public bool $freelance,
        public bool $remote,
        public string $budget,
        public bool $recent,
        public Seniority $seniority,
        public ?string $primaryLanguage = null,
        public ?int $relevance = null,
        public ?bool $locationOk = null,
    ) {
    }
}
