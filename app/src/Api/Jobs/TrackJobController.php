<?php

declare(strict_types=1);

namespace App\Api\Jobs;

use App\Repository\JobRepository;
use App\Tracking\ApplicationManager;
use Doctrine\ORM\EntityManagerInterface;
use App\Api\Shared\AbstractApiController;
use App\Repository\ApplicationRepository;
use App\Api\Applications\ApplicationPresenter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Repository\ApplicationStatusChangeRepository;

/**
 * `POST /api/v1/jobs/{id}/application` — ouvre le suivi d'une offre : une candidature **brouillon**. Rien n'est envoyé
 * à personne. Idempotent : si l'offre est déjà suivie, la candidature existante est renvoyée (`created: false`, 200).
 */
final class TrackJobController extends AbstractApiController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationStatusChangeRepository $history,
        private readonly ApplicationManager $manager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        '/jobs/{id}/application',
        name: 'api_jobs_track',
        requirements: ['id' => '\d{1,18}'],
        methods: ['POST']
    )]
    public function __invoke(int $id): JsonResponse
    {
        $job = $this->jobs->find($id);

        if ($job === null) {
            return $this->error(['message' => 'Offre introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $now = new \DateTimeImmutable();
        $existing = $this->applications->findOneByJob($job);
        $application = $existing ?? $this->manager->start($job, $now);

        if ($existing === null) {
            $this->entityManager->flush();
        }

        return $this->success(
            [
                'created' => $existing === null,
                'message' => $existing === null ? "Candidature ouverte (brouillon) : rien n'a été envoyé." : 'Cette offre est déjà suivie.',
                'application' => ApplicationPresenter::detail($application, $this->history->findHistory($application), $now),
            ],
            $existing === null ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
