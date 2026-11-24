<?php

declare(strict_types=1);

namespace App\Email\Parser;

/**
 * Parser de secours, **opt-in** : traite les expéditeurs listés dans `senderDomains` et ne retient
 * que les liens dont l'hôte figure dans `linkDomains`. Sans configuration, il ne reconnaît rien :
 * extraire des liens arbitraires d'un expéditeur quelconque serait dangereux.
 *
 * L'identifiant d'offre est le chemin + la requête de l'URL (sans paramètres de suivi usuels).
 */
final class GenericEmailParser extends AbstractLinkEmailParser
{
    private const array TRACKING_PARAMETERS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'mc_cid', 'mc_eid',
    ];

    /**
     * @param list<string|null> $senderDomains Config `app.email.generic_sender_domains`
     * @param list<string|null> $linkDomains Config `app.email.generic_link_domains`
     */
    public function __construct(
        private readonly array $senderDomains = [],
        private readonly array $linkDomains = [],
    ) {
    }

    public function platform(): string
    {
        return 'generic';
    }

    protected function senderDomains(): array
    {
        return $this->clean($this->senderDomains);
    }

    protected function resolveLink(string $url): ?JobLink
    {
        $parts = $this->parseUrl($url);
        $domains = $this->clean($this->linkDomains);
        if ($parts === null || $domains === [] || !$this->hostIn($parts['host'], $domains)) {
            return null;
        }

        if ($parts['path'] === '/' || $parts['path'] === '') {
            return null;
        }

        $query = array_diff_key($parts['query'], array_flip(self::TRACKING_PARAMETERS));
        ksort($query);
        $suffix = $query === [] ? '' : '?' . http_build_query($query);
        $path = rtrim($parts['path'], '/') ?: '/';

        return new JobLink('https://' . $parts['host'] . $path . $suffix, $parts['host'] . $path . $suffix);
    }

    /**
     * @param list<string|null> $values
     * @return list<string>
     */
    private function clean(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn (?string $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
