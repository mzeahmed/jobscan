<?php

declare(strict_types=1);

namespace App\Email\Parser;

/**
 * Alertes Welcome to the Jungle. Lien d'offre attendu :
 * `welcometothejungle.com/{langue}/companies/{entreprise}/jobs/{offre}`.
 * ⚠ Reconstruit sans email réel : à valider sur de vrais emails.
 */
final class WelcomeToTheJungleEmailParser extends AbstractLinkEmailParser
{
    public function platform(): string
    {
        return 'welcometothejungle';
    }

    protected function senderDomains(): array
    {
        return ['welcometothejungle.com'];
    }

    protected function resolveLink(string $url): ?JobLink
    {
        $parts = $this->parseUrl($url);
        if ($parts === null || !$this->hostIn($parts['host'], ['welcometothejungle.com'])) {
            return null;
        }

        if (preg_match('#^/([a-z]{2})/companies/([^/]+)/jobs/([^/?\#]+)/?$#', $parts['path'], $matches) !== 1) {
            return null;
        }

        return new JobLink(
            sprintf('https://www.welcometothejungle.com/%s/companies/%s/jobs/%s', $matches[1], $matches[2], $matches[3]),
            $matches[2] . '/' . $matches[3],
        );
    }
}
