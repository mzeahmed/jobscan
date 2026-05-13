<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\DTO\JobDTO;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SearxProvider implements JobProviderInterface
{
    /**
     * @param list<string> $searchQueries
     * @param list<string> $locations
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly array $searchQueries = [],
        private readonly array $locations = [],
    ) {
    }

    /**
     * @return JobDTO[]
     */
    public function fetch(): array
    {
        $jobs = [];

        foreach ($this->buildQueries() as $query) {
            foreach ($this->search($query) as $result) {
                $title = trim((string) ($result['title'] ?? ''));
                $url = trim((string) ($result['url'] ?? ''));
                $description = trim((string) ($result['content'] ?? ''));

                if ($title === '' || $url === '') {
                    continue;
                }

                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    continue;
                }

                if ($this->isClearlyIrrelevant($title, $url, $description)) {
                    continue;
                }

                $publishedAt = $this->extractPublishedDate($result);

                $jobs[$url] = new JobDTO(
                    title: $this->cleanText($title),
                    url: $url,
                    description: $this->cleanText($description),
                    source: 'searxng',
                    publishedAt: $publishedAt,
                );
            }
        }

        return array_values($jobs);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function search(string $query): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/') . '/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'json',
                    'language' => 'fr-FR',
                    'safesearch' => 0,
                    'time_range' => 'month',
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'JOBSCAN/1.0',
                ],
                'timeout' => 20,
            ]);

            $data = $response->toArray(false);

            if (!isset($data['results']) || !is_array($data['results'])) {
                return [];
            }

            return $data['results'];
        } catch (\Throwable $e) {
            $this->logger->warning('SearxProvider search failed.', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function isClearlyIrrelevant(string $title, string $url, string $description): bool
    {
        $text = strtolower($title . ' ' . $url . ' ' . $description);

        $blockedPatterns = [
            'tutorial',
            'cours',
            'formation',
            'manual',
            'documentation',
            'wikipedia',
            'youtube.com',
            'openclassrooms.com',
            'w3schools.com',
            'geeksforgeeks.org',
            'php.net',
            'github.com/php',
        ];

        foreach ($blockedPatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        $jobSignals = [
            'job',
            'jobs',
            'emploi',
            'emplois',
            'recrute',
            'hiring',
            'remote',
            'freelance',
            'mission',
            'cdi',
            'developer',
            'développeur',
            'backend',
            'full stack',
            'fullstack',
        ];

        foreach ($jobSignals as $signal) {
            if (str_contains($text, $signal)) {
                return false;
            }
        }

        return true;
    }

    private function parsePublishedDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
        }

        if (preg_match('/(\d{1,2})\s+(janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre)\s+(\d{4})/iu', $raw, $m)) {
            $months = [
                'janvier' => '01',
                'février' => '02',
                'mars' => '03',
                'avril' => '04',
                'mai' => '05',
                'juin' => '06',
                'juillet' => '07',
                'août' => '08',
                'septembre' => '09',
                'octobre' => '10',
                'novembre' => '11',
                'décembre' => '12',
            ];

            $month = mb_strtolower($m[2]);

            if (isset($months[$month])) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%s-%s-%02d', $m[3], $months[$month], (int) $m[1]));
                if ($date !== false) {
                    return $date;
                }
            }
        }

        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $raw, $m)) {
            $normalized = sprintf('%02d/%02d/%s', (int) $m[1], (int) $m[2], $m[3]);
            // Essaye mm/dd/yyyy (format SearXNG), puis dd/mm/yyyy
            foreach (['!m/d/Y', '!d/m/Y'] as $fmt) {
                $date = \DateTimeImmutable::createFromFormat($fmt, $normalized);
                if ($date !== false) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractPublishedDate(array $result): ?\DateTimeImmutable
    {
        $candidates = [
            'publishedDate' => $result['publishedDate'] ?? null,
            'pubdate' => $result['pubdate'] ?? null,
            'metadata' => $result['metadata'] ?? null,
            'content' => $result['content'] ?? null,
            'title' => $result['title'] ?? null,
        ];

        foreach ($candidates as $field => $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $date = $this->parsePublishedDate($candidate);

            if ($date === null) {
                continue;
            }

            if ($field !== 'publishedDate') {
                $this->logger->debug('Date extraite depuis le champ "{field}" : {date}', [
                    'field' => $field,
                    'date' => $date->format('Y-m-d'),
                    'url' => $result['url'] ?? '',
                ]);
            }

            return $date;
        }

        return null;
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }

    /**
     * @return list<string>
     */
    private function buildQueries(): array
    {
        $queries = [];

        foreach ($this->searchQueries as $baseQuery) {
            foreach ($this->locations as $location) {
                $queries[] = trim($baseQuery . ' ' . $location);
            }
        }

        return array_values(array_unique($queries));
    }
}
