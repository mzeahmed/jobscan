<?php

declare(strict_types=1);

namespace App\Api\Jobs;

use App\Repository\JobRepository;
use App\Api\Shared\AbstractApiController;
use App\Api\Shared\InvalidQueryException;
use App\Repository\ApplicationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/** `GET /api/v1/jobs` — offres filtrées, triées et paginées (mêmes filtres que l'interface Twig). */
final class ListJobsController extends AbstractApiController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ApplicationRepository $applications,
    ) {
    }

    #[Route(
        '/jobs',
        name: 'api_jobs_list',
        methods: ['GET']
    )]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $query = JobListQuery::fromQuery($request->query);
        } catch (InvalidQueryException $e) {
            return $this->invalidQuery($e);
        }

        $total = $this->jobs->countFiltered($query->filters);
        $totalPages = max(1, (int) ceil($total / $query->perPage));
        // Une page au-delà de la dernière est ramenée à la dernière : le client reçoit toujours une page cohérente.
        $page = min($query->page, $totalPages);
        $jobs = $this->jobs->findFilteredPaginated($query->filters, $page, $query->perPage);
        $applicationIds = $this->applications->idsByJobs($jobs);

        return $this->success([
            'items' => array_map(static fn ($job): array => JobPresenter::summary($job, $applicationIds[$job->getId()] ?? null), $jobs),
            'pagination' => ['page' => $page, 'per_page' => $query->perPage, 'total' => $total, 'total_pages' => $totalPages],
            'sources' => $this->jobs->findSources(),
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
