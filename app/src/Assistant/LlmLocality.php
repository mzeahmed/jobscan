<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Un moteur LLM est-il sur la machine (ou le réseau privé) de l'utilisateur, ou chez un tiers ?
 *
 * Par prudence, tout ce qui n'est pas clairement local est considéré comme distant : une adresse
 * `OLLAMA_BASE_URL` pointant vers Internet est un service distant même si le moteur s'appelle « ollama ».
 */
final class LlmLocality
{
    public static function isLocal(string $provider, ?string $baseUrl): bool
    {
        if (strtolower($provider) === 'gemini') {
            return false;
        }

        $host = is_string($baseUrl) ? parse_url($baseUrl, PHP_URL_HOST) : null;
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(trim($host, '[]'));
        if (in_array($host, ['localhost', '::1', 'host.docker.internal', 'host.containers.internal'], true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Boucle locale et réseaux privés (RFC 1918, lien local, ULA IPv6).
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
