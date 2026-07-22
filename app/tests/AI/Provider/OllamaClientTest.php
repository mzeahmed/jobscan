<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\AI\Provider\OllamaClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OllamaClientTest extends TestCase
{
    public function testReturnsContentFromChatCompletionsResponse(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            $this->assertSame('POST', $method);
            $this->assertSame('http://localhost:11434/v1/chat/completions', $url);

            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => '{"stack":["php"]}']]],
            ]));
        });

        $client = new OllamaClient($httpClient, new NullLogger(), 'http://localhost:11434/v1', 'qwen3:8b');

        $this->assertSame('{"stack":["php"]}', $client->analyze('system', 'user text'));
    }

    public function testReturnsNullOnEmptyContent(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'choices' => [['message' => ['content' => '']]],
        ])));

        $client = new OllamaClient($httpClient, new NullLogger(), 'http://localhost:11434/v1', 'qwen3:8b');

        $this->assertNull($client->analyze('system', 'user text'));
    }

    public function testReturnsNullOnTransportFailure(): void
    {
        $httpClient = new MockHttpClient(function () {
            throw new \RuntimeException('connection refused');
        });

        $client = new OllamaClient($httpClient, new NullLogger(), 'http://localhost:11434/v1', 'qwen3:8b');

        $this->assertNull($client->analyze('system', 'user text'));
    }
}
