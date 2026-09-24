<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\JobListingFilters;
use App\Repository\JobRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class JobController extends AbstractController
{
    private const int ITEMS_PER_PAGE = 10;

    public function __construct(
        private readonly JobRepository $jobRepository
    ) {
    }

    #[Route(
        '/',
        name: 'app_job_index'
    )]
    public function index(Request $request): Response
    {
        return $this->redirectToRoute('app_job');
    }

    #[Route(
        '/job',
        name: 'app_job',
        methods: ['GET']
    )]
    public function jobs(Request $request): Response
    {
        $filters = $this->filtersFromRequest($request);
        $requestedPage = max(1, $request->query->getInt('page', 1));
        $totalJobs = $this->jobRepository->countFiltered($filters);
        $totalPages = max(1, (int) ceil($totalJobs / self::ITEMS_PER_PAGE));
        $currentPage = min($requestedPage, $totalPages);
        $jobs = $this->jobRepository->findFilteredPaginated($filters, $currentPage, self::ITEMS_PER_PAGE);

        return $this->render('job/index.html.twig', [
            'jobs' => $jobs,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'perPage' => self::ITEMS_PER_PAGE,
            'totalJobs' => $totalJobs,
            'filters' => $filters,
            'sources' => $this->jobRepository->findSources(),
            'filterParams' => array_filter($request->query->all(), static fn ($key): bool => $key !== 'page', ARRAY_FILTER_USE_KEY),
        ]);
    }

    private function filtersFromRequest(Request $request): JobListingFilters
    {
        $choice = static fn (string $name, array $allowed): ?string => in_array($request->query->getString($name), $allowed, true)
            ? $request->query->getString($name)
            : null;
        $location = trim($request->query->getString('location'));
        $minimumScoreValue = trim($request->query->getString('min_score'));
        $minimumScore = $minimumScoreValue === '' ? null : filter_var($minimumScoreValue, FILTER_VALIDATE_INT);

        return new JobListingFilters(
            location: $location === '' ? null : $location,
            remote: match ($choice('remote', ['remote', 'onsite'])) {
                'remote' => true, 'onsite' => false, default => null
            },
            contractType: ($value = $choice('contract', array_column(ContractType::cases(), 'value'))) !== null ? ContractType::from($value) : null,
            seniority: ($value = $choice('seniority', array_column(Seniority::cases(), 'value'))) !== null ? Seniority::from($value) : null,
            freelance: match ($choice('freelance', ['yes', 'no'])) {
                'yes' => true, 'no' => false, default => null
            },
            minimumScore: is_int($minimumScore) && $minimumScore >= 0 && $minimumScore <= 100 ? $minimumScore : null,
            source: ($value = trim($request->query->getString('source'))) === '' ? null : $value,
            ageInDays: ($value = $choice('age', ['1', '7', '30'])) !== null ? (int) $value : null,
            notified: match ($choice('notified', ['yes', 'no'])) {
                'yes' => true, 'no' => false, default => null
            },
            sort: $choice('sort', ['date', 'score']) ?? 'date',
        );
    }
}
