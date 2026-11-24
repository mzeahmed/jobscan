<?php

declare(strict_types=1);

namespace App\Email\Parser;

/**
 * Alertes France Travail (ex-Pôle emploi). Lien d'offre attendu :
 * `candidat.francetravail.fr/offres/recherche/detail/{id}`.
 * ⚠ Reconstruit sans email réel : à valider sur de vrais emails.
 */
final class FranceTravailEmailParser extends AbstractLinkEmailParser
{
    private const array DOMAINS = ['francetravail.fr', 'pole-emploi.fr'];

    public function platform(): string
    {
        return 'francetravail';
    }

    protected function senderDomains(): array
    {
        return self::DOMAINS;
    }

    protected function resolveLink(string $url): ?JobLink
    {
        $parts = $this->parseUrl($url);
        if ($parts === null || !$this->hostIn($parts['host'], self::DOMAINS)) {
            return null;
        }

        if (preg_match('#^/offres/recherche/detail/([0-9A-Za-z]+)/?$#', $parts['path'], $matches) !== 1) {
            return null;
        }

        // Même identifiant et même URL publique que FranceTravailProvider : doublon certain entre les deux sources.
        return new JobLink('https://candidat.francetravail.fr/offres/recherche/detail/' . $matches[1], $matches[1]);
    }
}
