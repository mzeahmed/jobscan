<?php

declare(strict_types=1);

namespace App\Api\Applications;

use App\Api\Shared\AbstractApiController;
use App\Repository\ApplicationRepository;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Repository\ApplicationStatusChangeRepository;

/** `GET /api/v1/applications/{id}` — une candidature, son historique et les transitions encore permises. */
final class ShowApplicationController extends AbstractApiController
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ApplicationStatusChangeRepository $history,
    ) {
    }

    #[Route(
        '/applications/{id}',
        name: 'api_applications_show',
        requirements: ['id' => '\d{1,18}'],
        methods: ['GET']
    )]
    public function __invoke(int $id): JsonResponse
    {
        $application = $this->applications->find($id);

        if ($application === null) {
            return $this->error(['message' => 'Candidature introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->success(
            ['application' => ApplicationPresenter::detail($application, $this->history->findHistory($application), new \DateTimeImmutable())],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
