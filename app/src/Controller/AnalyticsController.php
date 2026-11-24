<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analytics\Window;
use App\Analytics\AnalyticsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class AnalyticsController extends AbstractController
{
    private const array WINDOWS = ['30d' => '30 jours', '90d' => '90 jours', '365d' => '1 an', 'all' => 'Tout'];

    public function __construct(
        private readonly AnalyticsService $analytics
    ) {
    }

    #[Route(
        '/analytics',
        name: 'app_analytics',
        methods: ['GET']
    )]
    public function index(Request $request): Response
    {
        $choice = $request->query->getString('since', '90d');
        $choice = array_key_exists($choice, self::WINDOWS) ? $choice : '90d';
        $now = new \DateTimeImmutable();
        $since = Window::since($choice, $now);

        return $this->render('analytics/index.html.twig', [
            'report' => $this->analytics->report($since === false ? null : $since, $now),
            'windows' => self::WINDOWS,
            'choice' => $choice,
            'titles' => ['source' => 'Par source de l\'offre', 'cv' => 'Par version de CV', 'profile' => 'Par profil de recherche', 'channel' => 'Par canal d\'envoi'],
        ]);
    }
}
