<?php

declare(strict_types=1);

namespace App\Processor;

/**
 * Teste l'appartenance de l'hôte d'une URL à une liste de domaines (sous-domaines inclus).
 */
final readonly class DomainFilter
{
    /**
     * @param array<array-key, mixed> $domains
     */
    public static function matches(string $url, array $domains): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        foreach ($domains as $domain) {
            if (!is_string($domain)) {
                continue;
            }

            $domain = strtolower(ltrim(rtrim(trim($domain), '.'), '.'));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }

        return false;
    }
}
