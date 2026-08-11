<?php

declare(strict_types=1);

namespace App\Provider;

use App\DTO\JobDto;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class RemoteOkProvider implements JobProviderInterface
{
    public function name(): string
    {
        return 'remoteok';
    }

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $apiUrl,
    ) {
    }

    /** @return JobDto[] */
    public function fetch(): array
    {
        try {
            $data = $this->httpClient->request('GET', $this->apiUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'JOBSCAN/1.0',
                ],
                'timeout' => 20,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('RemoteOkProvider failed.', [
                'api_url' => $this->apiUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $jobs = [];
        foreach ($data as $result) {
            if (!\is_array($result)) {
                continue;
            }

            $title = $this->optionalString($result['position'] ?? null);
            $url = $this->optionalString($result['url'] ?? $result['apply_url'] ?? null);
            if ($title === null || $url === null || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $jobs[$url] = new JobDto(
                title: $title,
                url: $url,
                description: $this->cleanText((string) ($result['description'] ?? '')),
                source: 'remoteok',
                publishedAt: $this->parseDate($result['date'] ?? null),
                company: $this->optionalString($result['company'] ?? null),
                location: $this->optionalString($result['location'] ?? null),
            );
        }

        return array_values($jobs);
    }

    private function optionalString(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = $this->cleanText($value);

        return $value === '' ? null : $value;
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
