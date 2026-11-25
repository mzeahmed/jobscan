<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * Corps JSON d'une requête d'écriture : `Content-Type: application/json` exigé (415 sinon), objet JSON exigé (400 sinon).
 *
 * Ces exceptions sont mises dans l'enveloppe d'erreur par `ApiExceptionSubscriber`.
 */
final class JsonBody
{
    /** @return array<string, mixed> */
    public static function read(Request $request): array
    {
        if ($request->getContentTypeFormat() !== 'json') {
            throw new UnsupportedMediaTypeHttpException();
        }

        $body = json_decode($request->getContent(), true);

        if (!\is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new BadRequestHttpException();
        }

        return $body;
    }
}
