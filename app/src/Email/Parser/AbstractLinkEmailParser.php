<?php

declare(strict_types=1);

namespace App\Email\Parser;

use App\DTO\JobDto;
use App\Email\EmailMessage;
use App\Processor\DomainFilter;

/**
 * Base des parsers d'alertes : une offre = un lien reconnu vers la plateforme.
 *
 * Principes de sécurité et de fiabilité :
 *  - seuls les liens dont l'hôte appartient aux domaines autorisés du parser sont retenus ;
 *    les liens de redirection de suivi (domaine tiers) sont ignorés, **jamais suivis** (SSRF) ;
 *  - les URLs sont normalisées vers la forme canonique de la plateforme (suivi retiré) ;
 *  - le HTML est lu avec libxml sans accès réseau, puis réduit à du texte : aucun HTML
 *    ni script de l'email n'est conservé ;
 *  - un titre absent ou générique (« Voir l'offre ») écarte l'offre plutôt que de l'inventer ;
 *  - entreprise, lieu et date de publication restent inconnus : ils ne sont pas extraits sans
 *    exemples réels (le texte de la carte de l'offre est conservé dans la description, et
 *    l'analyse IA en tirera ce qu'il contient).
 */
abstract class AbstractLinkEmailParser implements EmailParserInterface
{
    private const int MAX_TITLE_LENGTH = 200;
    private const int MAX_DESCRIPTION_LENGTH = 3000;
    private const string GENERIC_LABEL = '/^(voir|view|see|postuler|apply|candidater|consulter|afficher|d[ée]couvrir|en savoir plus|learn more|show|open|ouvrir|cliquez)\b/iu';

    /** @return list<string> Domaines d'expéditeur reconnus (sous-domaines inclus) */
    abstract protected function senderDomains(): array;

    /**
     * Reconnaît et normalise un lien d'offre ; `null` si ce n'est pas une offre de cette plateforme.
     */
    abstract protected function resolveLink(string $url): ?JobLink;

    public function supports(EmailMessage $email): bool
    {
        $domain = $email->senderDomain();

        return $domain !== '' && DomainFilter::matches('https://' . $domain, $this->senderDomains());
    }

    public function parse(EmailMessage $email): array
    {
        $html = $email->html !== null ? trim($email->html) : '';
        $candidates = $html !== '' ? $this->candidatesFromHtml($html) : [];

        if ($candidates === [] && trim((string) $email->text) !== '') {
            $candidates = $this->candidatesFromText((string) $email->text);
        }

        if ($candidates === [] && $html === '' && trim((string) $email->text) === '') {
            throw new EmailParsingException('Email sans contenu exploitable (ni HTML ni texte).');
        }

        $jobs = [];
        foreach ($candidates as $candidate) {
            $jobs[] = new JobDto(
                title: $candidate['title'],
                url: $candidate['link']->url,
                description: $candidate['description'],
                source: 'email-' . $this->platform(),
                externalId: $candidate['link']->externalId,
            );
        }

        return $jobs;
    }

    /**
     * @return list<array{link: JobLink, title: string, description: string}>
     */
    private function candidatesFromHtml(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            throw new EmailParsingException('HTML illisible.');
        }

        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//script|//style|//head') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        /** @var array<string, array{link: JobLink, anchors: list<\DOMElement>}> $groups */
        $groups = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (!$anchor instanceof \DOMElement) {
                continue;
            }

            $link = $this->resolveLink(trim($anchor->getAttribute('href')));
            if ($link === null) {
                continue;
            }

            $groups[$link->externalId]['link'] ??= $link;
            $groups[$link->externalId]['anchors'][] = $anchor;
        }

        $candidates = [];
        foreach ($groups as $group) {
            $title = $this->titleFrom($group['anchors']);
            if ($title === null) {
                continue;
            }

            $candidates[] = [
                'link' => $group['link'],
                'title' => $title,
                'description' => $this->cardText($group['anchors'][0], $group['link']->externalId) ?: $title,
            ];
        }

        return $candidates;
    }

    /**
     * @return list<array{link: JobLink, title: string, description: string}>
     */
    private function candidatesFromText(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $candidates = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            if (preg_match_all('#https?://[^\s<>"\')\]]+#iu', $line, $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $url) {
                $link = $this->resolveLink(rtrim($url, '.,;'));
                if ($link === null || isset($seen[$link->externalId])) {
                    continue;
                }

                // Bloc de texte situé juste avant le lien (jusqu'à une ligne vide ou un autre lien) :
                // le titre est sa première ligne non générique, comme dans une carte d'offre.
                $block = [];
                for ($previous = $index - 1; $previous >= 0 && $previous >= $index - 6; --$previous) {
                    $candidate = $this->clean($lines[$previous]);
                    if ($candidate === '') {
                        if ($block !== []) {
                            break;
                        }

                        continue;
                    }
                    if (preg_match('#https?://#i', $candidate) === 1) {
                        break;
                    }

                    array_unshift($block, $candidate);
                }
                $title = array_find($block, fn ($candidate) => !$this->isGenericLabel($candidate));

                if ($title === null || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
                    continue;
                }

                $seen[$link->externalId] = true;
                $candidates[] = [
                    'link' => $link,
                    'title' => $title,
                    'description' => mb_substr(implode(' ', $block), 0, self::MAX_DESCRIPTION_LENGTH),
                ];
            }
        }

        return $candidates;
    }

    /**
     * Premier libellé de lien utilisable comme titre : ni vide, ni générique, ni démesuré.
     *
     * @param list<\DOMElement> $anchors
     */
    private function titleFrom(array $anchors): ?string
    {
        foreach ($anchors as $anchor) {
            $label = $this->clean($anchor->textContent);

            if ($label !== '' && mb_strlen($label) <= self::MAX_TITLE_LENGTH && !$this->isGenericLabel($label)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Texte de la « carte » de l'offre : le plus grand ancêtre du lien qui ne contient aucune
     * autre offre. Contient typiquement entreprise et lieu, conservés tels quels.
     */
    private function cardText(\DOMElement $anchor, string $externalId): string
    {
        $card = $anchor;
        while (($parent = $card->parentNode) instanceof \DOMElement && strtolower($parent->nodeName) !== 'body') {
            if (!$this->containsOnlyOffer($parent, $externalId)) {
                break;
            }

            $card = $parent;
        }

        return mb_substr($this->clean($card->textContent), 0, self::MAX_DESCRIPTION_LENGTH);
    }

    private function containsOnlyOffer(\DOMElement $node, string $externalId): bool
    {
        foreach ($node->getElementsByTagName('a') as $anchor) {
            $link = $this->resolveLink(trim($anchor->getAttribute('href')));
            if ($link !== null && $link->externalId !== $externalId) {
                return false;
            }
        }

        return true;
    }

    private function isGenericLabel(string $label): bool
    {
        return mb_strlen($label) < 4 || (mb_strlen($label) < 40 && preg_match(self::GENERIC_LABEL, $label) === 1);
    }

    private function clean(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Retire les caractères de contrôle et d'espacement invisible qu'insèrent les emails marketing.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00AD}]/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Découpe une URL en http(s) uniquement, avec un hôte non vide ; sinon `null`.
     *
     * @return array{host: string, path: string, query: array<string, mixed>}|null
     */
    protected function parseUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['scheme'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        return ['host' => strtolower($parts['host']), 'path' => $parts['path'] ?? '/', 'query' => $query];
    }

    /**
     * @param list<string> $domains
     */
    protected function hostIn(string $host, array $domains): bool
    {
        return DomainFilter::matches('https://' . $host, $domains);
    }
}
