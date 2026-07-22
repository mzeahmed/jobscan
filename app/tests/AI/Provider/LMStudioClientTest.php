<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\AI\Provider\LMStudioClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LMStudioClientTest extends TestCase
{
    public function testReturnsContentFromChatCompletionsResponse(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            $this->assertSame('POST', $method);
            $this->assertSame('http://localhost:1234/v1/chat/completions', $url);

            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => '{"stack":["php"]}']]],
            ]));
        });

        $client = new LMStudioClient($httpClient, new NullLogger(), 'http://localhost:1234/v1', 'local-model');

        $this->assertSame('{"stack":["php"]}', $client->analyze('system', 'user text'));
    }

    public function testReturnsNullOnEmptyContent(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'choices' => [['message' => ['content' => '']]],
        ])));

        $client = new LMStudioClient($httpClient, new NullLogger(), 'http://localhost:1234/v1', 'local-model');

        $this->assertNull($client->analyze('system', 'user text'));
    }

    public function testReturnsNullOnTransportFailure(): void
    {
        $httpClient = new MockHttpClient(function () {
            throw new \RuntimeException('connection refused');
        });

        $client = new LMStudioClient($httpClient, new NullLogger(), 'http://localhost:1234/v1', 'local-model');

        $this->assertNull($client->analyze('system', 'user text'));
    }
}
