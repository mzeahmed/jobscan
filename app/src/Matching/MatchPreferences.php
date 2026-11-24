<?php

declare(strict_types=1);

namespace App\Matching;

use App\DTO\ContractType;
use App\Profile\Model\SearchProfile;
use App\Profile\Model\CandidateProfile;
use App\Profile\Model\RemotePreference;

/**
 * Préférences effectives d'un profil de recherche : ce qu'il définit, à défaut ce que déclare le candidat.
 * Une liste vide signifie « aucune contrainte ».
 */
final readonly class MatchPreferences
{
    /**
     * @param list<ContractType> $contracts
     * @param list<RemotePreference> $remoteModes
     * @param list<string> $locations
     * @param list<string> $titles
     * @param list<string> $keywords
     * @param list<string> $primarySkills
     * @param list<string> $excludedSkills
     */
    private function __construct(
        public array $contracts,
        public array $remoteModes,
        public array $locations,
        public array $titles,
        public array $keywords,
        public array $primarySkills,
        public array $excludedSkills,
    ) {
    }

    public static function resolve(CandidateProfile $candidate, SearchProfile $profile): self
    {
        return new self(
            $profile->contracts !== [] ? $profile->contracts : $candidate->contracts,
            $profile->remoteModes !== [] ? $profile->remoteModes : $candidate->remoteModes,
            $profile->locations !== [] ? $profile->locations : $candidate->location->cities,
            $profile->titles !== [] ? $profile->titles : $candidate->targetTitles,
            $profile->keywords,
            $profile->primarySkills,
            array_values(array_unique([...$profile->excludedSkills, ...$candidate->excludedSkills])),
        );
    }
}
