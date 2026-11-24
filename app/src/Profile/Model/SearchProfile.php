<?php

declare(strict_types=1);

namespace App\Profile\Model;

use App\DTO\ContractType;

/**
 * Stratégie de recherche indépendante (ex. « backend PHP/Symfony », « DevOps »). Une même offre
 * peut correspondre à plusieurs profils de recherche sans être dupliquée.
 */
final readonly class SearchProfile
{
    /**
     * @param list<string> $keywords Mots-clés de recherche
     * @param list<string> $titles Intitulés recherchés
     * @param list<string> $primarySkills Compétences prioritaires
     * @param list<string> $secondarySkills Compétences secondaires
     * @param list<string> $locations Lieux souhaités (vide : ceux du candidat)
     * @param list<ContractType> $contracts Types de contrat (vide : ceux du candidat)
     * @param list<RemotePreference> $remoteModes Modes de travail (vide : ceux du candidat)
     * @param list<string> $excludedKeywords Mots-clés éliminatoires
     * @param list<string> $excludedTitles Intitulés éliminatoires
     * @param list<string> $excludedSkills Compétences éliminatoires
     * @param int $minMatchScore Seuil minimal de compatibilité (0–100)
     * @param string|null $cvId Identifiant de la version de CV associée
     */
    public function __construct(
        public string $id,
        public string $name,
        public bool $enabled,
        public array $keywords,
        public array $titles,
        public array $primarySkills,
        public array $secondarySkills,
        public array $locations,
        public array $contracts,
        public array $remoteModes,
        public array $excludedKeywords,
        public array $excludedTitles,
        public array $excludedSkills,
        public int $minMatchScore,
        public ScoringWeights $weights,
        public ?string $cvId,
    ) {
    }
}
