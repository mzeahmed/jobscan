<?php

declare(strict_types=1);

namespace App\Cv;

use App\Entity\Job;
use App\Profile\ProfileRegistry;
use App\Repository\JobMatchRepository;

/**
 * Recommande une version de CV à partir du profil de recherche qui juge l'offre la plus pertinente.
 *
 * Aucune lecture du contenu des CV, aucune devinette : si l'offre n'est compatible avec aucun profil, ou si ce
 * profil n'a pas de CV associé, il n'y a pas de recommandation (`cv` vaut `null`) et la raison le dit.
 */
final readonly class CvRecommender
{
    public function __construct(
        private JobMatchRepository $matches,
        private ProfileRegistry $profiles,
    ) {
    }

    public function recommend(Job $job): CvRecommendation
    {
        $match = $this->matches->findBestEligible($job);
        if ($match === null) {
            return new CvRecommendation(null, null, 0, 'Aucun profil de recherche ne juge cette offre compatible (ou le matching n\'a pas été lancé).');
        }

        $profile = $this->profiles->searchProfile($match->getSearchProfileId());
        if ($profile === null) {
            return new CvRecommendation(null, $match->getSearchProfileId(), $match->getPriorityScore(), sprintf('Le profil « %s » n\'existe plus.', $match->getSearchProfileId()));
        }
        if ($profile->cvId === null) {
            return new CvRecommendation(null, $profile->id, $match->getPriorityScore(), sprintf('Le profil « %s » n\'a pas de CV associé (clé `cv`).', $profile->name));
        }

        $cv = $this->profiles->candidate()?->cvVersion($profile->cvId);
        if ($cv === null) {
            return new CvRecommendation(null, $profile->id, $match->getPriorityScore(), sprintf('La version de CV « %s » n\'est pas déclarée dans candidate.yaml.', $profile->cvId));
        }

        return new CvRecommendation($cv, $profile->id, $match->getPriorityScore(), sprintf('CV associé au profil « %s », le plus pertinent pour cette offre.', $profile->name));
    }
}
