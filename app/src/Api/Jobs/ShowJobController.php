<?php

declare(strict_types=1);

namespace App\Api\Jobs;

use App\Repository\JobRepository;
use App\Api\Shared\AbstractApiController;
use App\Repository\ApplicationRepository;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/** `GET /api/v1/jobs/{id}` — le détail d'une offre. */
final class ShowJobController extends AbstractApiController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ApplicationRepository $applications,
    ) {
    }

    #[Route(
        '/jobs/{id}',
        name: 'api_jobs_show',
        requirements: ['id' => '\d{1,18}'],
        methods: ['GET']
    )]
    public function __invoke(int $id): JsonResponse
    {
        $job = $this->jobs->find($id);

        if ($job === null) {
            return $this->error(['message' => 'Offre introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->success(
            ['job' => JobPresenter::detail($job, $this->applications->findOneByJob($job)?->getId())],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
