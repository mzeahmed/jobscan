<?php

declare(strict_types=1);

namespace App\Matching;

use App\Entity\Job;
use App\Entity\JobMatch;
use App\Profile\ProfileRegistry;
use App\Scoring\FreshnessScorer;
use App\Profile\Model\SearchProfile;
use App\Repository\JobMatchRepository;
use App\Profile\Model\CandidateProfile;

/**
 * Apparie une offre à chacun des profils de recherche actifs et conserve le résultat par profil.
 *
 * Aucun appel au LLM : le matching ne dépend que des exigences déjà extraites, du profil et des règles. Un
 * résultat est donc **recalculable à volonté** (profil modifié, poids changés) sans réanalyser l'annonce, et
 * n'est recalculé que s'il est périmé :
 * - l'empreinte du profil (candidat + profil de recherche) ou des règles de calcul a changé ;
 * - l'offre a été réanalysée depuis le dernier calcul.
 *
 * La fraîcheur, elle, évolue avec le temps : voir `refreshPriorities()`.
 */
final class JobMatcher
{
    /** @var array<string, string> */
    private array $fingerprints = [];

    public function __construct(
        private readonly ProfileRegistry $profiles,
        private readonly MatchScorer $scorer,
        private readonly FreshnessScorer $freshness,
        private readonly PriorityCalculator $priority,
        private readonly JobMatchRepository $matches,
    ) {
    }

    /**
     * Calcule les résultats manquants ou périmés d'une offre (tous, avec `$force`).
     *
     * Ne fait rien tant que l'offre n'a pas d'exigences extraites, ou qu'aucun candidat ni profil de
     * recherche actif n'est configuré.
     *
     * @return list<JobMatch> Résultats (re)calculés ; ceux déjà à jour ne sont pas retournés
     * @throws \App\Profile\InvalidProfileException si les fichiers de profil sont invalides
     */
    public function matchJob(Job $job, \DateTimeImmutable $now = new \DateTimeImmutable(), bool $force = false): array
    {
        $facts = JobFacts::fromJob($job);
        $set = $this->profiles->profiles();
        $candidate = $set->candidate;
        $searchProfiles = $set->enabledSearchProfiles();

        if ($facts === null || $candidate === null || $searchProfiles === []) {
            return [];
        }

        $freshness = $this->freshness->score($job->getPublishedAt(), $job->getPublicationDateConfidence(), $now);
        $computed = [];

        foreach ($searchProfiles as $profile) {
            $fingerprint = $this->fingerprint($candidate, $profile);
            $existing = $this->matches->findOneByJobAndProfile($job, $profile->id);

            if ($existing !== null && !$force && !$this->isStale($existing, $job, $fingerprint)) {
                continue;
            }

            $result = $this->scorer->match($candidate, $profile, $facts, $now);
            $priority = $this->priority->priority($result, $freshness);

            if ($existing === null) {
                $existing = new JobMatch($job, $profile->id, $result, $freshness, $priority, $fingerprint, MatchScorer::RULES_VERSION, $now);
                $this->matches->save($existing);
            } else {
                $existing->recompute($result, $freshness, $priority, $fingerprint, MatchScorer::RULES_VERSION, $now);
            }

            $computed[] = $existing;
        }

        return $computed;
    }

    /**
     * Recalcule fraîcheur et priorité d'un lot de résultats, sans refaire le matching.
     *
     * @param list<JobMatch> $matches
     * @return int Nombre de résultats mis à jour
     */
    public function refreshPriorities(array $matches, \DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        $updated = 0;
        foreach ($matches as $match) {
            $result = $match->getResult();
            if ($result === null) {
                continue;
            }

            $job = $match->getJob();
            $freshness = $this->freshness->score($job->getPublishedAt(), $job->getPublicationDateConfidence(), $now);
            $match->updatePriority($freshness, $this->priority->priority($result, $freshness));
            ++$updated;
        }

        return $updated;
    }

    /**
     * Supprime les résultats des profils de recherche désactivés ou supprimés.
     *
     * @throws \App\Profile\InvalidProfileException
     */
    public function removeObsolete(): int
    {
        return $this->matches->deleteExceptProfiles(array_map(
            static fn (SearchProfile $profile): string => $profile->id,
            $this->profiles->enabledSearchProfiles(),
        ));
    }

    /** Oublie les empreintes mémorisées (après un rechargement du profil). */
    public function reset(): void
    {
        $this->fingerprints = [];
        $this->profiles->reload();
    }

    private function isStale(JobMatch $match, Job $job, string $fingerprint): bool
    {
        $analyzedAt = $job->getAnalyzedAt();

        return $match->getFingerprint() !== $fingerprint || ($analyzedAt !== null && $analyzedAt > $match->getComputedAt());
    }

    /**
     * Empreinte de ce qui détermine le résultat : le candidat, le profil de recherche et les règles de calcul.
     * Le temps (fraîcheur) et les poids de priorité n'y figurent pas : ils se rafraîchissent séparément.
     */
    private function fingerprint(CandidateProfile $candidate, SearchProfile $profile): string
    {
        return $this->fingerprints[$profile->id] ??= hash('sha256', serialize([$candidate, $profile, $this->scorer->signature()]));
    }
}
