<?php

declare(strict_types=1);

namespace App\Api\Identity\Security;

use App\Api\Shared\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use App\Api\Identity\Token\AccessTokenService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authentifie les appels `/api/*` par le jeton d'accès (`Authorization: Bearer …`), sans session ni cookie.
 *
 * Sans en-tête, l'authentificateur ne fait rien : les routes publiques répondent, les autres sont refusées par
 * `access_control` (401 JSON). Avec un jeton invalide ou expiré : 401 immédiat.
 */
final class JwtAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly AccessTokenService $tokens)
    {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization');

        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            throw new BadCredentialsException();
        }

        $username = $this->tokens->verify($matches[1]);

        if ($username === null) {
            throw new BadCredentialsException();
        }

        return new SelfValidatingPassport(new UserBadge($username));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->start($request, $exception);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiResponse::error([], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer']);
    }
}
