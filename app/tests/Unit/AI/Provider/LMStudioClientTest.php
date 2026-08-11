<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use App\AI\Provider\LMStudioClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;

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

    public function testDisablesReasoningForQwen3Models(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) {
            $body = json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertStringEndsWith('/no_think', $body['messages'][0]['content']);

            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => '{"stack":["php"]}']]],
            ]));
        });
        $client = new LMStudioClient($httpClient, new NullLogger(), 'http://localhost:1234/v1', 'qwen3:8b');

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

    public function testReturnsNullAndLogsApiError(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            'error' => ['message' => 'No models loaded.'],
        ])));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('refusé par le provider'),
                ['error' => 'No models loaded.'],
            );
        $client = new LMStudioClient($httpClient, $logger, 'http://localhost:1234/v1', 'local-model');

        $this->assertNull($client->analyze('system', 'user text'));
    }

    public function testReturnsNullWhenChoicesAreMissing(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"id":"completion"}'));
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

    public function testRetriesTransientFailureAndLogsRecovery(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return $requests === 1
                ? new MockResponse('{"error":"temporarily unavailable"}', ['http_code' => 503])
                : new MockResponse('{"choices":[{"message":{"content":"{\\"stack\\":[\\"php\\"]}"}}]}');
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with(
                $this->stringContains('réussi après retry'),
                ['attempt' => 2, 'retries' => 1],
            );
        $client = new LMStudioClient($this->retryable($httpClient), $logger, 'http://localhost:1234/v1', 'local-model');

        $this->assertSame('{"stack":["php"]}', $client->analyze('system', 'user text'));
        $this->assertSame(2, $requests);
    }

    public function testStopsAfterThreeTransientFailures(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"error":"unavailable"}', ['http_code' => 503]);
        });
        $client = new LMStudioClient($this->retryable($httpClient), new NullLogger(), 'http://localhost:1234/v1', 'local-model');

        $this->assertNull($client->analyze('system', 'user text'));
        $this->assertSame(3, $requests);
    }

    public function testDoesNotRetryPermanentClientError(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"error":"invalid request"}', ['http_code' => 400]);
        });
        $client = new LMStudioClient($this->retryable($httpClient), new NullLogger(), 'http://localhost:1234/v1', 'local-model');

        $this->assertNull($client->analyze('system', 'user text'));
        $this->assertSame(1, $requests);
    }

    public function testRetriesRateLimitAndTransportErrors(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return match ($requests) {
                1 => new MockResponse('', ['error' => 'connection reset']),
                2 => new MockResponse('{"error":"rate limited"}', ['http_code' => 429]),
                default => new MockResponse('{"choices":[{"message":{"content":"ok"}}]}'),
            };
        });
        $client = new LMStudioClient($this->retryable($httpClient), new NullLogger(), 'http://localhost:1234/v1', 'local-model');

        $this->assertSame('ok', $client->analyze('system', 'user text'));
        $this->assertSame(3, $requests);
    }

    private function retryable(HttpClientInterface $httpClient): RetryableHttpClient
    {
        return new RetryableHttpClient(
            $httpClient,
            new GenericRetryStrategy([0, 429, 500, 502, 503, 504], 0, 2.0, 0, 0.0),
            2,
            new NullLogger(),
        );
    }
}
