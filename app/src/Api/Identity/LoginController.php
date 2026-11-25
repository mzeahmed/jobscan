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
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /api/v1/auth/login` — ouvre une session : jeton d'accès dans le corps, jeton de rafraîchissement en cookie.
 *
 * Défenses : limitation du débit par adresse et par couple adresse/identifiant, message unique pour « identifiant
 * inconnu » et « mauvais mot de passe », temps de calcul comparable dans les deux cas, corps JSON exigé.
 */
final class LoginController extends AbstractApiController
{
    private const int MAX_FIELD_LENGTH = 256;

    public function __construct(
        private readonly UiUserProvider $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AccessTokenService $accessTokens,
        private readonly RefreshTokenService $refreshTokens,
        private readonly RateLimiterFactoryInterface $apiLoginIpLimiter,
        private readonly RateLimiterFactoryInterface $apiLoginUserLimiter,
    ) {
    }

    #[Route(
        '/auth/login',
        name: 'api_auth_login',
        methods: ['POST']
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->users->isConfigured() || !$this->accessTokens->isConfigured()) {
            return $this->error(['error' => 'auth_not_configured', 'message' => "L'authentification n'est pas configurée sur ce serveur."], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($request->getContentTypeFormat() !== 'json') {
            return $this->error([], JsonResponse::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $body = json_decode($request->getContent(), true);
        $username = \is_array($body) ? ($body['username'] ?? null) : null;
        $password = \is_array($body) ? ($body['password'] ?? null) : null;

        if (!\is_string($username) || !\is_string($password) || $username === '' || $password === '') {
            return $this->error(['message' => "L'identifiant et le mot de passe sont requis."], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($username) > self::MAX_FIELD_LENGTH || mb_strlen($password) > self::MAX_FIELD_LENGTH) {
            return $this->error(['message' => 'Identifiant ou mot de passe trop long.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ip = $request->getClientIp() ?? 'inconnue';
        $limits = [
            $this->apiLoginIpLimiter->create($ip)->consume(),
            $this->apiLoginUserLimiter->create($ip . '|' . mb_strtolower($username))->consume(),
        ];

        foreach ($limits as $limit) {
            if (!$limit->isAccepted()) {
                $seconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

                return $this->error(
                    ['message' => sprintf('Trop de tentatives : réessayez dans %d minute%s.', $minutes = (int) ceil($seconds / 60), $minutes > 1 ? 's' : '')],
                    JsonResponse::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => (string) $seconds],
                );
            }
        }

        $user = null;

        try {
            $user = $this->users->loadUserByIdentifier($username);
            $valid = $this->hasher->isPasswordValid($user, $password);
        } catch (UserNotFoundException) {
            // Même travail de hachage que pour un vrai compte : le temps de réponse ne révèle pas si l'identifiant existe.
            $this->hasher->isPasswordValid(new InMemoryUser('inconnu', $this->hasher->hashPassword(new InMemoryUser('inconnu', null), 'inconnu')), $password);
            $valid = false;
        }

        if (!$valid || $user === null) {
            return $this->error(['error' => 'invalid_credentials', 'message' => 'Identifiants invalides.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $this->apiLoginUserLimiter->create($ip . '|' . mb_strtolower($username))->reset();

        $response = $this->success($this->accessTokens->session($user), headers: ['Cache-Control' => 'no-store']);
        $response->headers->setCookie(RefreshCookie::create($request, $this->refreshTokens->start($user->getUserIdentifier()), $this->refreshTokens->ttl()));

        return $response;
    }
}
