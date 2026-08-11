<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use Psr\Log\NullLogger;
use App\Provider\SearxProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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
            1,
            0,
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
            1,
            0,
        );

        self::assertCount(2, $provider->fetch());
        self::assertSame(2, $requests);
        self::assertCount(2, $provider->fetch());
        self::assertSame(2, $requests, 'Le second passage doit utiliser le cache.');
    }

    public function testPaginatesCachesEachPageAndDeduplicatesResults(): void
    {
        $requestedPages = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestedPages): MockResponse {
            $page = (int) ($options['query']['pageno'] ?? 0);
            $requestedPages[] = $page;

            return new MockResponse(json_encode([
                'results' => $page <= 2 ? [[
                    'title' => 'Développeur PHP',
                    'url' => 'https://jobs.example/same-job',
                    'content' => 'emploi PHP remote',
                ]] : [],
            ]));
        });

        $provider = new SearxProvider(
            $httpClient,
            new NullLogger(),
            'https://searx.test',
            new ArrayAdapter(),
            ['php'],
            [],
            1,
            1,
            3600,
            3,
            0,
        );

        self::assertCount(1, $provider->fetch());
        self::assertSame([1, 2, 3], $requestedPages);

        self::assertCount(1, $provider->fetch());
        self::assertSame([1, 2, 3], $requestedPages, 'Toutes les pages, y compris la page vide, doivent venir du cache.');
    }

    public function testStopsPaginationAfterAnEmptyPage(): void
    {
        $requestedPages = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestedPages): MockResponse {
            $page = (int) ($options['query']['pageno'] ?? 0);
            $requestedPages[] = $page;

            return new MockResponse(json_encode([
                'results' => $page === 1 ? [[
                    'title' => 'Développeur PHP',
                    'url' => 'https://jobs.example/1',
                    'content' => 'emploi PHP',
                ]] : [],
            ]));
        });

        $provider = new SearxProvider(
            $httpClient,
            new NullLogger(),
            'https://searx.test',
            new ArrayAdapter(),
            ['php'],
            [],
            1,
            1,
            3600,
            5,
            0,
        );

        self::assertCount(1, $provider->fetch());
        self::assertSame([1, 2], $requestedPages);
    }

    public function testWaitsOnlyBetweenHttpBatches(): void
    {
        $delays = [];
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'results' => [[
                'title' => 'Développeur PHP',
                'url' => 'https://jobs.example/' . uniqid(),
                'content' => 'emploi PHP',
            ]],
        ])));

        $provider = new SearxProvider(
            $httpClient,
            new NullLogger(),
            'https://searx.test',
            new ArrayAdapter(),
            ['php', 'symfony', 'wordpress'],
            [],
            3,
            2,
            3600,
            1,
            500,
            static function (int $delayMs) use (&$delays): void {
                $delays[] = $delayMs;
            },
        );

        $provider->fetch();

        self::assertSame([500], $delays);
    }
}
