<?php

declare(strict_types=1);

namespace App\Provider;

use App\DTO\JobDto;
use Psr\Log\LoggerInterface;
use App\DTO\PublicationDateSource;
use App\Monitoring\FetchDiagnostics;
use App\DTO\PublicationDateConfidence;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Agrège les offres d'emploi depuis des flux RSS 2.0 et Atom.
 *
 * Chaque URL de flux configurée dans `app.job_feed_urls` est interrogée
 * indépendamment. Les erreurs réseau ou XML d'un flux sont journalisées et
 * ignorées sans interrompre le traitement des autres flux.
 *
 * Les doublons intra-provider (même URL dans plusieurs flux) sont éliminés
 * en indexant les résultats par URL avant de les retourner.
 */
final readonly class RsFeedProvider implements JobProviderInterface
{
    public function name(): string
    {
        return 'rss';
    }

    /**
     * @param list<string|null> $feedUrls URLs optionnelles des flux à interroger (config `app.job_feed_urls`)
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private array $feedUrls = [],
        private ?CollectionPolicy $collectionPolicy = null,
        private ?FetchDiagnostics $diagnostics = null,
    ) {
    }

    /**
     * Sain dès qu'au moins un flux configuré répond, ou si aucun flux n'est configuré
     * (le provider est alors un no-op légitime).
     */
    public function isHealthy(): bool
    {
        $urls = array_values(array_filter(
            $this->feedUrls,
            static fn (?string $url): bool => is_string($url) && trim($url) !== '',
        ));

        if ($urls === []) {
            return true;
        }

        foreach ($urls as $url) {
            try {
                $status = $this->httpClient->request('GET', $url, [
                    'headers' => ['User-Agent' => 'JOBSCAN/1.0'],
                    'timeout' => 5,
                ])->getStatusCode();

                if ($status < 400) {
                    return true;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('FeedProvider health check failed.', [
                    'feed_url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    /**
     * Récupère et agrège toutes les offres depuis les flux configurés.
     *
     * @return JobDto[]
     */
    public function fetch(): array
    {
        $results = [];
        $configured = array_filter(
            $this->feedUrls,
            static fn (?string $url): bool => \is_string($url) && trim($url) !== '',
        );

        if ($configured === []) {
            $this->diagnostics?->markDisabled($this->name());

            return [];
        }

        foreach ($configured as $feedUrl) {

            try {
                $response = $this->httpClient
                    ->request('GET', $feedUrl, [
                        'timeout' => 20,
                        'headers' => [
                            'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.8',
                            'User-Agent' => 'JOBSCAN/1.0',
                        ],
                    ]);

                $xml = $response->getContent();
                $jobs = $this->parseFeed($xml, $feedUrl);

                foreach ($jobs as $job) {
                    if ($this->collectionPolicy !== null && !$this->collectionPolicy->allows($job->url)) {
                        continue;
                    }

                    $results[$job->url] = $job;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('FeedProvider failed.', [
                    'feed_url' => $feedUrl,
                    'error' => $e->getMessage(),
                ]);
                $this->diagnostics?->record($this->name(), $e->getMessage());
            }
        }

        return array_values($results);
    }

    /**
     * Parse une date de publication depuis une chaîne brute (RFC 2822, ISO 8601, etc.).
     *
     * Retourne `null` si la chaîne est vide ou non parseable, sans jamais lever d'exception.
     */
    private function parsePubDate(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Détecte le format du flux (RSS 2.0 ou Atom) et délègue au parseur approprié.
     *
     * @return JobDto[]
     */
    private function parseFeed(string $xml, string $feedUrl): array
    {
        $jobs = [];

        libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml);

        if ($feed === false) {
            $this->logger->warning('FeedProvider invalid XML.', [
                'feed_url' => $feedUrl,
            ]);
            $this->diagnostics?->record($this->name(), 'XML invalide : ' . $feedUrl);

            return [];
        }

        // RSS 2.0
        if (isset($feed->channel->item)) {
            return $this->rss20($feed->channel->item, $this->sourceFromFeedUrl($feedUrl));
        }

        // Atom
        if (isset($feed->entry)) {
            $jobs = $this->atom($feed->entry, $this->sourceFromFeedUrl($feedUrl));
        }

        return $jobs;
    }

    /**
     * Parse les items d'un flux RSS 2.0.
     *
     * Extrait `<title>`, `<link>`, `<description>` et `<pubDate>` de chaque item.
     * Les items sans titre ou sans URL sont ignorés.
     *
     * @param  array<mixed>|\SimpleXMLElement  $items
     *
     * @return JobDto[]
     */
    private function rss20(array | \SimpleXMLElement $items, string $source): array
    {
        $jobs = [];

        foreach ($items as $item) {
            $title = trim((string) ($item->title ?? ''));
            $url = trim((string) ($item->link ?? ''));
            $description = trim(strip_tags((string) ($item->description ?? '')));

            if ($title === '' || $url === '') {
                continue;
            }

            $publishedAt = $this->parsePubDate((string) ($item->pubDate ?? ''));
            $guid = trim((string) ($item->guid ?? ''));

            $jobs[] = new JobDto(
                title: $title,
                url: $url,
                description: $description,
                source: $source,
                publishedAt: $publishedAt,
                externalId: $guid === '' ? null : $guid,
                publicationDateSource: $publishedAt === null ? PublicationDateSource::Unknown : PublicationDateSource::Feed,
                publicationDateConfidence: $publishedAt === null ? PublicationDateConfidence::Unknown : PublicationDateConfidence::Medium,
            );
        }

        return $jobs;
    }

    /**
     * Parse les entrées d'un flux Atom.
     *
     * Extrait `<title>`, `<link href>`, `<summary>` (ou `<content>`) et
     * `<published>` (ou `<updated>` en fallback) de chaque entrée.
     * Les entrées sans titre ou sans URL sont ignorées.
     *
     * @param  array<mixed>|\SimpleXMLElement  $entries
     *
     * @return JobDto[]
     */
    private function atom(array | \SimpleXMLElement $entries, string $source): array
    {
        $jobs = [];

        foreach ($entries as $entry) {
            $title = trim((string) ($entry->title ?? ''));
            $url = '';
            $description = trim(strip_tags((string) ($entry->summary ?? $entry->content ?? '')));

            if (isset($entry->link)) {
                foreach ($entry->link as $link) {
                    $href = trim((string) $link['href']);
                    if ($href !== '') {
                        $url = $href;
                        break;
                    }
                }
            }

            if ($title === '' || $url === '') {
                continue;
            }

            // Atom : `updated` n'est pas une date de publication, d'où une confiance moindre.
            $rawPublished = trim((string) ($entry->published ?? ''));
            $rawDate = $rawPublished !== '' ? $rawPublished : trim((string) ($entry->updated ?? ''));
            $publishedAt = $this->parsePubDate($rawDate);
            $id = trim((string) ($entry->id ?? ''));

            $jobs[] = new JobDto(
                title: $title,
                url: $url,
                description: $description,
                source: $source,
                publishedAt: $publishedAt,
                externalId: $id === '' ? null : $id,
                publicationDateSource: $publishedAt === null ? PublicationDateSource::Unknown : PublicationDateSource::Feed,
                publicationDateConfidence: match (true) {
                    $publishedAt === null => PublicationDateConfidence::Unknown,
                    $rawPublished !== '' => PublicationDateConfidence::Medium,
                    default => PublicationDateConfidence::Low,
                },
            );
        }

        return $jobs;
    }

    /**
     * Produit un libellé court et stable à partir du domaine du flux.
     */
    private function sourceFromFeedUrl(string $feedUrl): string
    {
        $host = parse_url($feedUrl, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return 'feed';
        }

        $host = preg_replace('/^www\./i', '', $host);
        $source = explode('.', (string) $host)[0];

        return $source !== '' ? strtolower($source) : 'feed';
    }
}
