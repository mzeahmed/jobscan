<?php

declare(strict_types=1);

namespace App\Email\Parser;

/**
 * Alertes Indeed. Lien d'offre attendu : `{pays}.indeed.com/viewjob?jk={clé}` ou `/rc/clk?jk={clé}`.
 * ⚠ Reconstruit sans email réel : à valider sur de vrais emails.
 */
final class IndeedEmailParser extends AbstractLinkEmailParser
{
    public function platform(): string
    {
        return 'indeed';
    }

    protected function senderDomains(): array
    {
        return ['indeed.com'];
    }

    protected function resolveLink(string $url): ?JobLink
    {
        $parts = $this->parseUrl($url);
        if ($parts === null || !$this->hostIn($parts['host'], ['indeed.com'])) {
            return null;
        }

        if (preg_match('#^/(?:viewjob|rc/clk(?:/dl)?)/?$#', $parts['path']) !== 1) {
            return null;
        }

        $key = $parts['query']['jk'] ?? null;
        if (!is_string($key) || preg_match('/^[0-9a-f]{8,32}$/i', $key) !== 1) {
            return null;
        }

        return new JobLink(sprintf('https://%s/viewjob?jk=%s', $parts['host'], strtolower($key)), strtolower($key));
    }
}
