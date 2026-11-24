<?php

declare(strict_types=1);

namespace App\Api\Probe;

use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Sonde de disponibilité : la base répond. Publique et sans enveloppe, comme `HealthController`.
 *
 * Seul un booléen par dépendance est renvoyé : le détail d'une panne (qui peut contenir un chemin ou une
 * adresse) n'est jamais exposé. Seule la classe de l'exception est journalisée.
 */
final class ReadyController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/health/ready',
        name: 'probe_ready',
        methods: ['GET']
    )]
    public function __invoke(): JsonResponse
    {
        try {
            $database = 1 === (int) $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $exception) {
            $this->logger->warning('ready_check_failed', ['dependency' => 'database', 'exception' => $exception::class]);
            $database = false;
        }

        return $this->json(
            ['status' => $database ? 'ready' : 'unavailable', 'dependencies' => ['database' => $database]],
            $database ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
