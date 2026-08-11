<?php

declare(strict_types=1);

namespace App\Tests\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\Provider\RsFeedProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RsFeedProviderTest extends TestCase
{
    public function testSkipsMissingOptionalFeedUrls(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0"><channel><item>
                    <title>Développeur PHP</title>
                    <link>https://jobs.example/42</link>
                    <description>Symfony remote</description>
                </item></channel></rss>
                XML);
        });

        $provider = new RsFeedProvider($client, new NullLogger(), [null, '', 'https://feed.example/rss']);

        self::assertCount(1, $provider->fetch());
        self::assertSame(1, $requests);
    }

    public function testUsesFeedDomainAsSourceForRssAndAtom(): void
    {
        $client = new MockHttpClient([
            new MockResponse(<<<'XML'
                <?xml version="1.0"?>
                <rss version="2.0"><channel><item>
                    <title>Développeur PHP</title>
                    <link>https://jobs.example/rss-42</link>
                    <description>Symfony remote</description>
                </item></channel></rss>
                XML),
            new MockResponse(<<<'XML'
                <?xml version="1.0"?>
                <feed xmlns="http://www.w3.org/2005/Atom"><entry>
                    <title>Développeur Symfony</title>
                    <link href="https://jobs.example/atom-42" />
                    <summary>Mission freelance</summary>
                </entry></feed>
                XML),
        ]);

        $provider = new RsFeedProvider($client, new NullLogger(), [
            'https://www.reddit.com/r/forhire/.rss',
            'https://remoteok.com/jobs.atom',
        ]);

        $jobs = $provider->fetch();

        self::assertSame('reddit', $jobs[0]->source);
        self::assertSame('remoteok', $jobs[1]->source);
    }

    public function testFallsBackToFeedForMalformedFeedUrl(): void
    {
        $client = new MockHttpClient(new MockResponse(<<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0"><channel><item>
                <title>Développeur PHP</title>
                <link>https://jobs.example/42</link>
            </item></channel></rss>
            XML));

        $provider = new RsFeedProvider($client, new NullLogger(), ['not-a-url']);

        self::assertSame('feed', $provider->fetch()[0]->source);
    }
}
