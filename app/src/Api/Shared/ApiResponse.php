<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Fabrique les réponses JSON publiques de l'API.
 *
 * Contrat commun, identique à celui de l'API Yoostart de référence :
 * `{code: string, success: bool, data: {message: string, …}}`. Le front en dépend : une réponse JSON de l'API
 * ne sort jamais de cette enveloppe (hors sondes d'infrastructure, voir `Probe/`).
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function success(array $data = [], int $status = JsonResponse::HTTP_OK, array $headers = []): JsonResponse
    {
        $data['message'] ??= 'OK';

        return new JsonResponse(['code' => 'success', 'success' => true, 'data' => $data], $status, $headers);
    }

    /**
     * @param array{error?: string, message?: string, ...<string, mixed>} $data `error` : code d'erreur, `message` : texte pour l'utilisateur
     * @param array<string, string> $headers
     */
    public static function error(array $data, int $status = JsonResponse::HTTP_BAD_REQUEST, array $headers = []): JsonResponse
    {
        $code = \is_string($data['error'] ?? null) && '' !== $data['error'] ? $data['error'] : ApiErrors::code($status);
        $message = \is_string($data['message'] ?? null) && '' !== $data['message'] ? $data['message'] : ApiErrors::message($status);
        unset($data['error']);
        $data['message'] = $message;

        return new JsonResponse(['code' => $code, 'success' => false, 'data' => $data], $status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function noContent(array $headers = []): JsonResponse
    {
        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT, $headers);
    }
}
