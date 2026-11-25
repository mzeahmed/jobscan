<?php

declare(strict_types=1);

namespace App\Api\Shared;

/**
 * Une URL d'annonce n'est exposée que si c'est une URL `http(s)` : une annonce piégée ne peut pas y mettre `javascript:`
 * (le front en fait un lien cliquable). Même règle que le filtre Twig `safe_url`.
 */
final class SafeUrl
{
    public static function http(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('~^https?://[^\s<>"\']+$~i', $url) === 1 ? $url : null;
    }
}
