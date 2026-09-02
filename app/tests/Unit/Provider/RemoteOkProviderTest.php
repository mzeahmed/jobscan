<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\Provider\RemoteOkProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RemoteOkProviderTest extends TestCase
{
    public function testMapsJobsAndIgnoresMetadataAndMalformedEntries(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            ['legal' => 'API terms'],
            [
                'position' => 'Développeur PHP',
                'url' => 'https://remoteok.com/jobs/42',
                'description' => '<strong>Symfony</strong> remote',
                'date' => '2026-08-10T10:17:15+00:00',
                'company' => 'Acme',
                'location' => 'Paris',
            ],
            ['position' => 'Missing URL'],
        ])));

        $jobs = new RemoteOkProvider($client, new NullLogger(), 'https://remoteok.test/api')->fetch();

        self::assertCount(1, $jobs);
        self::assertSame('Développeur PHP', $jobs[0]->title);
        self::assertSame('Symfony remote', $jobs[0]->description);
        self::assertSame('remoteok', $jobs[0]->source);
        self::assertSame('Acme', $jobs[0]->company);
        self::assertSame('Paris', $jobs[0]->location);
        self::assertSame('2026-08-10', $jobs[0]->publishedAt?->format('Y-m-d'));
    }

    public function testDeduplicatesByUrlAndKeepsLastResult(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            ['position' => 'First title', 'url' => 'https://remoteok.com/jobs/42'],
            ['position' => 'Last title', 'url' => 'https://remoteok.com/jobs/42'],
        ])));

        $jobs = new RemoteOkProvider($client, new NullLogger(), 'https://remoteok.test/api')->fetch();

        self::assertCount(1, $jobs);
        self::assertSame('Last title', $jobs[0]->title);
    }

    public function testReturnsEmptyListOnHttpFailure(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $jobs = new RemoteOkProvider($client, new NullLogger(), 'https://remoteok.test/api')->fetch();

        self::assertSame([], $jobs);
    }

    public function testIsHealthyReflectsTheProbeResponse(): void
    {
        $ok = new RemoteOkProvider(new MockHttpClient(new MockResponse('[]')), new NullLogger(), 'https://remoteok.test/api');
        $ko = new RemoteOkProvider(new MockHttpClient(new MockResponse('', ['http_code' => 503])), new NullLogger(), 'https://remoteok.test/api');

        self::assertTrue($ok->isHealthy());
        self::assertFalse($ko->isHealthy());
    }
}
