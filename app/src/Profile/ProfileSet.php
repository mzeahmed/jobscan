<?php

declare(strict_types=1);

namespace App\Profile;

use App\Profile\Model\SearchProfile;
use App\Profile\Model\CandidateProfile;

/**
 * Profil candidat et profils de recherche chargés et validés, avec les avertissements éventuels.
 */
final readonly class ProfileSet
{
    /**
     * @param list<SearchProfile> $searchProfiles
     * @param list<string> $warnings Incohérences non bloquantes détectées à la validation
     */
    public function __construct(
        public ?CandidateProfile $candidate,
        public array $searchProfiles,
        public array $warnings = [],
    ) {
    }

    /** @return list<SearchProfile> */
    public function enabledSearchProfiles(): array
    {
        return array_values(array_filter($this->searchProfiles, static fn (SearchProfile $profile): bool => $profile->enabled));
    }

    public function searchProfile(string $id): ?SearchProfile
    {
        return array_find($this->searchProfiles, static fn (SearchProfile $profile): bool => $profile->id === $id);
    }
}
