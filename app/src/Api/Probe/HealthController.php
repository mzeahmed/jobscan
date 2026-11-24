<?php

declare(strict_types=1);

namespace App\Api\Probe;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Sonde de vivacité : le processus répond. Publique, sans enveloppe (lue par Docker ou un répartiteur de charge),
 * hors du préfixe `/api/v1`. Ne révèle rien sur l'installation.
 */
final class HealthController extends AbstractController
{
    #[Route(
        '/health',
        name: 'probe_health',
        methods: ['GET']
    )]
    public function __invoke(): JsonResponse
    {
        return $this->json(['status' => 'ok', 'service' => 'jobscan']);
    }
}
