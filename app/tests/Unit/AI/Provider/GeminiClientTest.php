<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\AI\Provider\GeminiClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;

class GeminiClientTest extends TestCase
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

        $client = new GeminiClient($httpClient, new NullLogger(), 'api-key', 'gemini-2.0-flash');

        $this->assertSame('{"stack":["php"]}', $client->analyze('system', 'user text'));
    }

    public function testReturnsNullOnEmptyContent(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'candidates' => [],
        ])));

        $client = new GeminiClient($httpClient, new NullLogger(), 'api-key', 'gemini-2.0-flash');

        $this->assertNull($client->analyze('system', 'user text'));
    }

    public function testReturnsNullOnTransportFailure(): void
    {
        $httpClient = new MockHttpClient(function () {
            throw new \RuntimeException('connection refused');
        });

        $client = new GeminiClient($httpClient, new NullLogger(), 'api-key', 'gemini-2.0-flash');

        $this->assertNull($client->analyze('system', 'user text'));
    }

    public function testRetriesTransientHttpFailure(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return $requests === 1
                ? new MockResponse('{"error":"unavailable"}', ['http_code' => 503])
                : new MockResponse('{"candidates":[{"content":{"parts":[{"text":"ok"}]}}]}');
        });
        $retryable = new RetryableHttpClient(
            $httpClient,
            new GenericRetryStrategy([0, 429, 500, 502, 503, 504], 0, 2.0, 0, 0.0),
            2,
            new NullLogger(),
        );
        $client = new GeminiClient($retryable, new NullLogger(), 'api-key', 'gemini-2.0-flash');

        $this->assertSame('ok', $client->analyze('system', 'user text'));
        $this->assertSame(2, $requests);
    }
}
