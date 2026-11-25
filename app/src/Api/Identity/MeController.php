<?php

declare(strict_types=1);

namespace App\Api\Identity;

use App\Api\Shared\AbstractApiController;
use App\Api\Identity\Token\AccessTokenService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\User\UserInterface;

/** `GET /api/v1/auth/me` — l'utilisateur du jeton d'accès (protégée par `access_control`). */
final class MeController extends AbstractApiController
{
    #[Route(
        '/auth/me',
        name: 'api_auth_me',
        methods: ['GET']
    )]
    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof UserInterface) {
            return $this->error([], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return $this->success(['user' => AccessTokenService::userView($user)], headers: ['Cache-Control' => 'no-store']);
    }
}
