<?php

declare(strict_types=1);

namespace App\Controller;

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
        $requestedPage = max(1, $request->query->getInt('page', 1));
        $totalJobs = $this->jobRepository->countAll();
        $totalPages = max(1, (int) ceil($totalJobs / self::ITEMS_PER_PAGE));
        $currentPage = min($requestedPage, $totalPages);
        $jobs = $this->jobRepository->findPaginated($currentPage, self::ITEMS_PER_PAGE);

        return $this->render('job/index.html.twig', [
            'jobs' => $jobs,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'perPage' => self::ITEMS_PER_PAGE,
            'totalJobs' => $totalJobs,
        ]);
    }
}
