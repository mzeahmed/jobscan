<?php

declare(strict_types=1);

namespace App\Api\Info;

use App\Api\Shared\AbstractApiController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Racine de l'API versionnée : identifie le service et sa version. Publique, dans l'enveloppe commune,
 * elle permet au front de vérifier la connexion à l'API et d'exercer le contrat de réponse.
 */
final class ApiInfoController extends AbstractApiController
{
    #[Route(
        '',
        name: 'api_info',
        methods: ['GET']
    )]
    public function __invoke(): JsonResponse
    {
        return $this->success(['service' => 'jobscan-api', 'version' => 'v1']);
    }
}
