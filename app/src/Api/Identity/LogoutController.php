<?php

declare(strict_types=1);

namespace App\Api\Identity;

use App\Api\Identity\Http\RefreshCookie;
use App\Api\Shared\AbstractApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Api\Identity\Token\RefreshTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `POST /api/v1/auth/logout` — révoque la famille du cookie de rafraîchissement et l'efface. Répond 204 (aussi sans
 * cookie : idempotent). Le front ne considère la session fermée que sur cette confirmation.
 */
final class LogoutController extends AbstractApiController
{
    public function __construct(private readonly RefreshTokenService $refreshTokens)
    {
    }

    #[Route(
        '/auth/logout',
        name: 'api_auth_logout',
        methods: ['POST']
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $raw = RefreshCookie::read($request);

        if ($raw !== null) {
            $this->refreshTokens->revoke($raw);
        }

        $response = $this->noContent(['Cache-Control' => 'no-store']);
        $response->headers->setCookie(RefreshCookie::clear($request));

        return $response;
    }
}
