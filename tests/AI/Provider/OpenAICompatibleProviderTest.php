<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use App\AI\Provider\OpenAICompatibleProvider;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAICompatibleProviderTest extends TestCase
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

        $provider = new OpenAICompatibleProvider($httpClient, new NullLogger(), 'http://localhost:11434/v1', 'ollama', 'qwen3:8b');

        $this->assertSame('{"stack":["php"]}', $provider->complete('system', 'user text'));
    }

    public function testReturnsNullOnEmptyContent(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'choices' => [['message' => ['content' => '']]],
        ])));

        $provider = new OpenAICompatibleProvider($httpClient, new NullLogger(), 'http://localhost:11434/v1', 'ollama', 'qwen3:8b');

        $this->assertNull($provider->complete('system', 'user text'));
    }

    public function testReturnsNullOnTransportFailure(): void
    {
        $httpClient = new MockHttpClient(function () {
            throw new \RuntimeException('connection refused');
        });

        $provider = new OpenAICompatibleProvider($httpClient, new NullLogger(), 'http://localhost:11434/v1', 'ollama', 'qwen3:8b');

        $this->assertNull($provider->complete('system', 'user text'));
    }
}
