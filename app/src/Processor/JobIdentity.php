<?php

declare(strict_types=1);

namespace App\Processor;

use App\DTO\JobDto;
use App\Entity\Job;

final readonly class JobIdentity
{
    private const array TRACKING_PARAMETERS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'mc_cid', 'mc_eid', 'ref', 'source',
    ];

    public function canonicalUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (in_array(strtolower((string) $key), self::TRACKING_PARAMETERS, true)) {
                unset($query[$key]);
            }
        }
        ksort($query);

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = rtrim($parts['path'] ?? '/', '/') ?: '/';
        $normalizedQuery = http_build_query($query);

        return sprintf('%s://%s%s%s%s', $scheme, $host, $port, $path, $normalizedQuery === '' ? '' : '?' . $normalizedQuery);
    }

    public function fingerprint(JobDto $job): ?string
    {
        if (($job->company === null || trim($job->company) === '')
            && ($job->location === null || trim($job->location) === '')) {
            return null;
        }

        $identity = implode('|', [
            Job::normalizeTitle($job->title),
            $this->normalize($job->company),
            $this->normalize($job->location),
        ]);

        return hash('sha256', $identity);
    }

    private function normalize(?string $value): string
    {
        return Job::normalizeTitle($value ?? '');
    }
}
