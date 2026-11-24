<?php

declare(strict_types=1);

namespace App\Profile;

use App\Profile\Model\SearchProfile;
use App\Profile\Model\CandidateProfile;

/**
 * Accès au profil chargé. Le chargement est paresseux et mémorisé : un fichier de profil invalide
 * ne perturbe que les commandes qui s'en servent, jamais le démarrage de l'application.
 */
final class ProfileRegistry
{
    private ?ProfileSet $set = null;

    public function __construct(private readonly ProfileLoader $loader)
    {
    }

    /** @throws InvalidProfileException */
    public function profiles(): ProfileSet
    {
        return $this->set ??= $this->loader->load();
    }

    /** @throws InvalidProfileException */
    public function candidate(): ?CandidateProfile
    {
        return $this->profiles()->candidate;
    }

    /**
     * @return list<SearchProfile>
     * @throws InvalidProfileException
     */
    public function enabledSearchProfiles(): array
    {
        return $this->profiles()->enabledSearchProfiles();
    }

    /** @throws InvalidProfileException */
    public function searchProfile(string $id): ?SearchProfile
    {
        return $this->profiles()->searchProfile($id);
    }

    /** Oublie le profil mémorisé : le prochain accès relit les fichiers. */
    public function reload(): void
    {
        $this->set = null;
    }
}
