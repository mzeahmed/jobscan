<?php

declare(strict_types=1);

namespace App\Email\Parser;

/**
 * Alertes LinkedIn. Lien d'offre attendu : `linkedin.com/(comm/)jobs/view/{id}` (avec ou sans slug).
 * ⚠ Reconstruit sans email réel : à valider sur de vrais emails.
 */
final class LinkedInEmailParser extends AbstractLinkEmailParser
{
    public function platform(): string
    {
        return 'linkedin';
    }

    protected function senderDomains(): array
    {
        return ['linkedin.com'];
    }

    protected function resolveLink(string $url): ?JobLink
    {
        $parts = $this->parseUrl($url);
        if ($parts === null || !$this->hostIn($parts['host'], ['linkedin.com'])) {
            return null;
        }

        if (preg_match('#^/(?:comm/)?jobs/view/(?:[^/?\#]*?-)?(\d{6,})/?$#', $parts['path'], $matches) !== 1) {
            return null;
        }

        return new JobLink('https://www.linkedin.com/jobs/view/' . $matches[1], $matches[1]);
    }
}
