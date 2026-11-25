<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Base des contrôleurs JSON de l'API : toutes leurs réponses passent par l'enveloppe `ApiResponse`.
 *
 * Un contrôleur retourne ses erreurs prévues (`error()`), et laisse les autres (404, 405, exception inattendue)
 * à `ApiExceptionSubscriber`, qui les met dans la même enveloppe.
 */
abstract class AbstractApiController extends AbstractController
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function success(array $data = [], int $status = JsonResponse::HTTP_OK, array $headers = []): JsonResponse
    {
        return ApiResponse::success($data, $status, $headers);
    }

    /**
     * @param array{error?: string, message?: string, ...<string, mixed>} $data
     * @param array<string, string> $headers
     */
    protected function error(array $data, int $status = JsonResponse::HTTP_BAD_REQUEST, array $headers = []): JsonResponse
    {
        return ApiResponse::error($data, $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    protected function noContent(array $headers = []): JsonResponse
    {
        return ApiResponse::noContent($headers);
    }

    /** Réponse `422` : le message général et, par champ, ce qui est refusé. */
    protected function invalidInput(InvalidInputException $e): JsonResponse
    {
        return $this->error(['message' => 'Un ou plusieurs champs sont invalides.', 'errors' => $e->errors], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }
}
