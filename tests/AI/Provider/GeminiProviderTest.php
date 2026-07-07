<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\AI\Provider\GeminiProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GeminiProviderTest extends TestCase
{
    public function testReturnsContentFromGenerateContentResponse(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            $this->assertSame('POST', $method);
            $this->assertSame(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
                $url
            );

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => '{"stack":["php"]}']]]]],
            ]));
        });

        $provider = new GeminiProvider($httpClient, new NullLogger(), 'api-key', 'gemini-2.0-flash');

        $this->assertSame('{"stack":["php"]}', $provider->complete('system', 'user text'));
    }

    public function testReturnsNullOnEmptyContent(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'candidates' => [],
        ])));

        $provider = new GeminiProvider($httpClient, new NullLogger(), 'api-key', 'gemini-2.0-flash');

        $this->assertNull($provider->complete('system', 'user text'));
    }

    public function testReturnsNullOnTransportFailure(): void
    {
        $httpClient = new MockHttpClient(function () {
            throw new \RuntimeException('connection refused');
        });

        $provider = new GeminiProvider($httpClient, new NullLogger(), 'api-key', 'gemini-2.0-flash');

        $this->assertNull($provider->complete('system', 'user text'));
    }
}
