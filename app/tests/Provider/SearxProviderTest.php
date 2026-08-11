<?php

declare(strict_types=1);

namespace App\Tests\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\Provider\SearxProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearxProviderTest extends TestCase
{
    public function testContinuesWhenCreatingARequestThrows(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;
            if ($requests === 1) {
                throw new \RuntimeException('network unavailable');
            }

            return new MockResponse(json_encode([
                'results' => [[
                    'title' => 'Développeur PHP',
                    'url' => 'https://jobs.example/2',
                    'content' => 'emploi PHP',
                ]],
            ]));
        });

        $provider = new SearxProvider(
            $httpClient,
            new NullLogger(),
            'https://searx.test',
            new ArrayAdapter(),
            ['first', 'second'],
            [],
            2,
            2,
            3600,
        );

        self::assertCount(1, $provider->fetch());
        self::assertSame(2, $requests);
    }

    public function testUsesLocalizedQueriesQuotaAndCache(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            ++$requests;
            $query = (string) ($options['query']['q'] ?? '');

            return new MockResponse(json_encode([
                'results' => [[
                    'title' => 'Développeur PHP ' . $requests,
                    'url' => 'https://jobs.example/' . $requests,
                    'content' => 'emploi PHP remote ' . $query,
                    'location' => 'Paris',
                    'company' => 'Acme',
                ]],
            ]));
        });

        $provider = new SearxProvider(
            $httpClient,
            new NullLogger(),
            'https://searx.test',
            new ArrayAdapter(),
            ['php Paris', 'symfony'],
            ['Paris', 'Remote'],
            2,
            2,
            3600,
        );

        self::assertCount(2, $provider->fetch());
        self::assertSame(2, $requests);
        self::assertCount(2, $provider->fetch());
        self::assertSame(2, $requests, 'Le second passage doit utiliser le cache.');
    }
}
