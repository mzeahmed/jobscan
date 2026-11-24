<?php

declare(strict_types=1);

namespace App\Email\Parser;

/**
 * Alertes HelloWork. Lien d'offre attendu : `hellowork.com/fr-fr/emplois/{id}.html`.
 * ⚠ Reconstruit sans email réel : à valider sur de vrais emails.
 */
final class HelloWorkEmailParser extends AbstractLinkEmailParser
{
    public function platform(): string
    {
        return 'hellowork';
    }

    protected function senderDomains(): array
    {
        return ['hellowork.com'];
    }

    protected function resolveLink(string $url): ?JobLink
    {
        $parts = $this->parseUrl($url);
        if ($parts === null || !$this->hostIn($parts['host'], ['hellowork.com'])) {
            return null;
        }

        if (preg_match('#^/fr-fr/emplois/(\d+)\.html$#', $parts['path'], $matches) !== 1) {
            return null;
        }

        return new JobLink('https://www.hellowork.com/fr-fr/emplois/' . $matches[1] . '.html', $matches[1]);
    }
}
