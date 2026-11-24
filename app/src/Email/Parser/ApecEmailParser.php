<?php

declare(strict_types=1);

namespace App\Email\Parser;

/**
 * Alertes Apec. Lien d'offre attendu : `apec.fr/candidat/recherche-emploi.html/emploi/detail-offre/{id}`.
 * ⚠ Reconstruit sans email réel : à valider sur de vrais emails.
 */
final class ApecEmailParser extends AbstractLinkEmailParser
{
    public function platform(): string
    {
        return 'apec';
    }

    protected function senderDomains(): array
    {
        return ['apec.fr'];
    }

    protected function resolveLink(string $url): ?JobLink
    {
        $parts = $this->parseUrl($url);
        if ($parts === null || !$this->hostIn($parts['host'], ['apec.fr'])) {
            return null;
        }

        if (preg_match('#^/candidat/recherche-emploi\.html/emploi/detail-offre/([0-9A-Za-z]+)/?$#', $parts['path'], $matches) !== 1) {
            return null;
        }

        return new JobLink('https://www.apec.fr/candidat/recherche-emploi.html/emploi/detail-offre/' . $matches[1], $matches[1]);
    }
}
