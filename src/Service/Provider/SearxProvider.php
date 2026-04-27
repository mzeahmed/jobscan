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

                $jobs[$url] = new JobDTO(
                    title: $this->cleanText($title),
                    url: $url,
                    description: $this->cleanText($description),
                    source: 'searxng',
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
