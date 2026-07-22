<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\AI\Provider\GeminiClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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
}
