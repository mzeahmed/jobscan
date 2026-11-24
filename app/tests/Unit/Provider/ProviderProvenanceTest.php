<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use Psr\Log\NullLogger;
use App\Provider\SearxProvider;
use PHPUnit\Framework\TestCase;
use App\Provider\RsFeedProvider;
use App\DTO\PublicationDateSource;
use App\Provider\CollectionPolicy;
use App\Provider\RemoteOkProvider;
use App\Monitoring\FetchDiagnostics;
use App\DTO\PublicationDateConfidence;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Dates, identifiants externes, diagnostics et restrictions de collecte des providers.
 */
final class ProviderProvenanceTest extends TestCase
{
    public function testRemoteOkReportsAnApiDateWithHighConfidenceAndItsExternalId(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            ['id' => '1234', 'position' => 'PHP', 'url' => 'https://remoteok.com/jobs/1234', 'date' => '2026-08-10T10:17:15+00:00'],
            ['position' => 'Sans date', 'url' => 'https://remoteok.com/jobs/5'],
        ])));

        $jobs = new RemoteOkProvider($client, new NullLogger(), 'https://remoteok.test/api')->fetch();

        self::assertSame('1234', $jobs[0]->externalId);
        self::assertSame(PublicationDateSource::Api, $jobs[0]->publicationDateSource);
        self::assertSame(PublicationDateConfidence::High, $jobs[0]->publicationDateConfidence);
        self::assertNull($jobs[1]->publishedAt, 'Une date absente reste inconnue.');
        self::assertSame(PublicationDateConfidence::Unknown, $jobs[1]->publicationDateConfidence);
    }

    public function testRemoteOkRecordsADiagnosticInsteadOfSilentlyReturningNothing(): void
    {
        $diagnostics = new FetchDiagnostics();
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $jobs = new RemoteOkProvider($client, new NullLogger(), 'https://remoteok.test/api', $diagnostics)->fetch();

        self::assertSame([], $jobs);
        self::assertCount(1, $diagnostics->drain('remoteok')['errors']);
    }

    public function testRssFlagsAnAtomUpdatedDateAsLowConfidence(): void
    {
        $client = new MockHttpClient([
            new MockResponse(<<<'XML'
                <?xml version="1.0"?>
                <feed xmlns="http://www.w3.org/2005/Atom">
                  <entry><title>A</title><id>urn:a</id><link href="https://jobs.example/a"/><published>2026-11-20T08:00:00Z</published><updated>2026-11-23T08:00:00Z</updated></entry>
                  <entry><title>B</title><link href="https://jobs.example/b"/><updated>2026-11-23T08:00:00Z</updated></entry>
                </feed>
                XML),
        ]);

        $jobs = new RsFeedProvider($client, new NullLogger(), ['https://jobs.example/feed'])->fetch();

        self::assertSame('2026-11-20', $jobs[0]->publishedAt?->format('Y-m-d'));
        self::assertSame(PublicationDateConfidence::Medium, $jobs[0]->publicationDateConfidence);
        self::assertSame('urn:a', $jobs[0]->externalId);
        self::assertSame(PublicationDateConfidence::Low, $jobs[1]->publicationDateConfidence);
    }

    public function testRssReportsItselfDisabledWhenNoFeedIsConfigured(): void
    {
        $diagnostics = new FetchDiagnostics();

        $jobs = new RsFeedProvider(new MockHttpClient(), new NullLogger(), [null, ''], null, $diagnostics)->fetch();

        self::assertSame([], $jobs);
        self::assertTrue($diagnostics->drain('rss')['disabled']);
    }

    public function testRssReportsAFailingFeedWhileKeepingTheOthers(): void
    {
        $diagnostics = new FetchDiagnostics();
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('<rss version="2.0"><channel><item><title>PHP</title><link>https://jobs.example/1</link></item></channel></rss>'),
        ]);

        $jobs = new RsFeedProvider($client, new NullLogger(), ['https://a.example/rss', 'https://b.example/rss'], null, $diagnostics)->fetch();

        self::assertCount(1, $jobs);
        self::assertCount(1, $diagnostics->drain('rss')['errors']);
    }

    public function testCollectionPolicyKeepsBlockedPlatformsOutOfDiscoveryProviders(): void
    {
        $policy = new CollectionPolicy(['linkedin.com']);
        $rss = new MockHttpClient(new MockResponse(<<<'XML'
            <rss version="2.0"><channel>
              <item><title>PHP</title><link>https://www.linkedin.com/jobs/view/1</link></item>
              <item><title>PHP</title><link>https://jobs.example/2</link></item>
            </channel></rss>
            XML));
        $searx = new MockHttpClient(new MockResponse(json_encode(['results' => [
            ['title' => 'Développeur PHP', 'url' => 'https://fr.linkedin.com/jobs/view/3', 'content' => 'emploi PHP'],
            ['title' => 'Développeur PHP', 'url' => 'https://jobs.example/4', 'content' => 'emploi PHP', 'publishedDate' => '2026-11-20T08:00:00'],
        ]])));

        $rssJobs = new RsFeedProvider($rss, new NullLogger(), ['https://feed.example/rss'], $policy)->fetch();
        $searxJobs = new SearxProvider($searx, new NullLogger(), 'https://searx.test', new ArrayAdapter(), ['php'], [], 1, 1, 3600, 1, 0, null, null, $policy)->fetch();

        self::assertSame(['https://jobs.example/2'], array_map(static fn ($job) => $job->url, $rssJobs));
        self::assertSame(['https://jobs.example/4'], array_map(static fn ($job) => $job->url, $searxJobs));
        self::assertSame(PublicationDateSource::Search, $searxJobs[0]->publicationDateSource);
        self::assertSame(PublicationDateConfidence::Medium, $searxJobs[0]->publicationDateConfidence);
    }

    public function testSearxMarksADateGuessedFromFreeTextAsLowConfidence(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['results' => [
            ['title' => 'Développeur PHP', 'url' => 'https://jobs.example/9', 'content' => 'emploi PHP publié le 12 novembre 2026'],
        ]])));

        $jobs = new SearxProvider($client, new NullLogger(), 'https://searx.test', new ArrayAdapter(), ['php'], [], 1, 1, 3600, 1, 0)->fetch();

        self::assertSame('2026-11-12', $jobs[0]->publishedAt?->format('Y-m-d'));
        self::assertSame(PublicationDateSource::Text, $jobs[0]->publicationDateSource);
        self::assertSame(PublicationDateConfidence::Low, $jobs[0]->publicationDateConfidence);
    }
}
