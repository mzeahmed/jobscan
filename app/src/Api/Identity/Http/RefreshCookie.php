<?php

declare(strict_types=1);

namespace App\Api\Identity\Http;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Cookie du jeton de rafraîchissement : illisible par JavaScript (HttpOnly), jamais envoyé par un site tiers
 * (SameSite=Strict), limité aux routes d'authentification (le jeton ne voyage pas avec chaque appel d'API) et
 * réservé à HTTPS dès que la requête l'est.
 */
final class RefreshCookie
{
    public const string NAME = 'jobscan_refresh';
    public const string PATH = '/api/v1/auth';

    public static function create(Request $request, string $token, int $ttl): Cookie
    {
        return Cookie::create(self::NAME, $token, time() + $ttl, self::PATH, null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT);
    }

    public static function clear(Request $request): Cookie
    {
        return Cookie::create(self::NAME, '', 1, self::PATH, null, $request->isSecure(), true, false, Cookie::SAMESITE_STRICT);
    }

    public static function read(Request $request): ?string
    {
        $value = $request->cookies->get(self::NAME);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
