<?php

declare(strict_types=1);

namespace App\Tracking;

use App\Entity\Job;
use App\Entity\Application;
use App\Profile\ProfileRegistry;
use App\Repository\ApplicationRepository;
use App\Repository\ApplicationStatusChangeRepository;

/**
 * Cas d'usage du suivi de candidatures. Ne fait que consigner ce que l'utilisateur déclare : rien n'est envoyé
 * à un recruteur, rien n'est déduit ni inventé. L'appelant vide l'unité de travail (`flush`).
 */
final readonly class ApplicationManager
{
    public function __construct(
        private ApplicationRepository $applications,
        private ApplicationStatusChangeRepository $history,
        private ProfileRegistry $profiles,
    ) {
    }

    /**
     * Ouvre une candidature (brouillon) pour une offre ; une offre n'en a qu'une.
     *
     * @throws InvalidApplicationException offre déjà suivie, profil ou CV inconnu
     */
    public function start(Job $job, \DateTimeImmutable $now, ?string $searchProfileId = null, ?string $cvVersionId = null): Application
    {
        if ($this->applications->findOneByJob($job) !== null) {
            throw new InvalidApplicationException('Cette offre a déjà une candidature.');
        }

        $application = new Application($job, $now);
        if ($searchProfileId !== null) {
            $this->assertSearchProfileExists($searchProfileId);
            $application->setSearchProfileId($searchProfileId);
        }

        if ($cvVersionId !== null) {
            $this->attachCv($application, $cvVersionId);
        }

        $this->applications->save($application);
        $this->history->save(new \App\Entity\ApplicationStatusChange($application, null, ApplicationStatus::Draft, $now));

        return $application;
    }

    /**
     * @throws InvalidApplicationException transition interdite ou date incohérente
     */
    public function changeStatus(Application $application, ApplicationStatus $to, \DateTimeImmutable $at, ?string $note = null): void
    {
        $this->history->save($application->transitionTo($to, $at, $note));
    }

    /**
     * Associe une version de CV déclarée dans le profil candidat. Le libellé est copié : il reste lisible si le profil change.
     *
     * @throws InvalidApplicationException version de CV inconnue
     */
    public function attachCv(Application $application, string $cvVersionId): void
    {
        $cv = $this->profiles->candidate()?->cvVersion($cvVersionId);
        if ($cv === null) {
            throw new InvalidApplicationException(sprintf('Version de CV inconnue : « %s » (voir candidate.yaml).', $cvVersionId));
        }

        $application->setCvVersion($cv->id, $cv->label);
    }

    public function decide(Job $job, ?JobUserState $state, \DateTimeImmutable $now): void
    {
        $job->setUserState($state, $now);
    }

    private function assertSearchProfileExists(string $id): void
    {
        if ($this->profiles->searchProfile($id) === null) {
            throw new InvalidApplicationException(sprintf('Profil de recherche inconnu : « %s ».', $id));
        }
    }
}
