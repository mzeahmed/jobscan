<?php

declare(strict_types=1);

namespace App\Api\Identity;

use App\Security\UiUserProvider;
use App\Api\Identity\Http\RefreshCookie;
use App\Api\Shared\AbstractApiController;
use Symfony\Component\HttpFoundation\Request;
use App\Api\Identity\Token\AccessTokenService;
use Symfony\Component\Routing\Attribute\Route;
use App\Api\Identity\Token\RefreshTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Api\Identity\Token\InvalidRefreshTokenException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * `POST /api/v1/auth/refresh` — échange le cookie de rafraîchissement contre un nouveau jeton d'accès et un nouveau
 * cookie (rotation : l'ancien ne sert plus). Toute anomalie donne le même 401 et efface le cookie.
 */
final class RefreshController extends AbstractApiController
{
    public function __construct(
        private readonly UiUserProvider $users,
        private readonly AccessTokenService $accessTokens,
        private readonly RefreshTokenService $refreshTokens,
    ) {
    }

    #[Route(
        '/auth/refresh',
        name: 'api_auth_refresh',
        methods: ['POST']
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->users->isConfigured() || !$this->accessTokens->isConfigured()) {
            return $this->error(['error' => 'auth_not_configured', 'message' => "L'authentification n'est pas configurée sur ce serveur."], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        $raw = RefreshCookie::read($request);

        if ($raw === null) {
            return $this->error([], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $rotated = $this->refreshTokens->rotate($raw);
            $user = $this->users->loadUserByIdentifier($rotated['username']);
        } catch (InvalidRefreshTokenException | UserNotFoundException) {
            $response = $this->error([], JsonResponse::HTTP_UNAUTHORIZED);
            $response->headers->setCookie(RefreshCookie::clear($request));

            return $response;
        }

        $response = $this->success($this->accessTokens->session($user), headers: ['Cache-Control' => 'no-store']);
        $response->headers->setCookie(RefreshCookie::create($request, $rotated['token'], $this->refreshTokens->ttl()));

        return $response;
    }
}
