<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\DTO\JobDTO;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RsFeedProvider implements JobProviderInterface
{
    /**
     * @param string[] $feedUrls
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly array $feedUrls = [],
    ) {
    }

    /**
     * @return JobDTO[]
     */
    public function fetch(): array
    {
        $results = [];

        foreach ($this->feedUrls as $feedUrl) {
            if ($feedUrl === '') {
                continue;
            }

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
                    $results[$job->url] = $job;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('FeedProvider failed.', [
                    'feed_url' => $feedUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_values($results);
    }

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
     * @return JobDTO[]
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

            return [];
        }

        // RSS 2.0
        if (isset($feed->channel->item)) {
            return $this->rss20($feed->channel->item);
        }

        // Atom
        if (isset($feed->entry)) {
            $jobs = $this->atom($feed->entry);
        }

        return $jobs;
    }

    /**
     * @param array<mixed>|\SimpleXMLElement $items
     * @return JobDTO[]
     */
    private function rss20(array | \SimpleXMLElement $items): array
    {
        $jobs = [];

        foreach ($items as $item) {
            $title = trim((string) ($item->title ?? ''));
            $url = trim((string) ($item->link ?? ''));
            $description = trim(strip_tags((string) ($item->description ?? '')));

            if ($title === '' || $url === '') {
                continue;
            }

            $jobs[] = new JobDTO(
                title: $title,
                url: $url,
                description: $description,
                source: 'feed',
                publishedAt: $this->parsePubDate((string) ($item->pubDate ?? '')),
            );
        }

        return $jobs;
    }

    /**
     * @param array<mixed>|\SimpleXMLElement $entries
     * @return JobDTO[]
     */
    private function atom(array | \SimpleXMLElement $entries): array
    {
        $jobs = [];

        foreach ($entries as $entry) {
            $title = trim($entry->title ?? '');
            $url = '';
            $description = trim(strip_tags($entry->summary ?? $entry->content ?? ''));

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

            $rawDate = trim($entry->published ?? $entry->updated ?? '');

            $jobs[] = new JobDTO(
                title: $title,
                url: $url,
                description: $description,
                source: 'feed',
                publishedAt: $this->parsePubDate($rawDate),
            );
        }

        return $jobs;
    }
}
