<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\AI\Provider\GeminiClient;
use App\AI\Provider\OllamaClient;
use App\AI\Provider\LMStudioClient;
use App\AI\Provider\LLMClientFactory;
use Symfony\Component\HttpClient\MockHttpClient;

class LLMClientFactoryTest extends TestCase
{
    private OllamaClient $ollama;
    private LMStudioClient $lmStudio;
    private GeminiClient $gemini;

    protected function setUp(): void
    {
        $httpClient = new MockHttpClient();
        $this->ollama = new OllamaClient($httpClient, new NullLogger(), 'http://localhost:11434/v1', 'qwen3:8b');
        $this->lmStudio = new LMStudioClient($httpClient, new NullLogger(), 'http://localhost:1234/v1', 'local-model');
        $this->gemini = new GeminiClient($httpClient, new NullLogger(), 'api-key', 'gemini-2.0-flash');
    }

    public function testCreatesOllamaClientByDefault(): void
    {
        $factory = new LLMClientFactory($this->ollama, $this->lmStudio, $this->gemini, 'ollama');

        $this->assertSame($this->ollama, $factory->create());
    }

    public function testCreatesLMStudioClientWhenConfigured(): void
    {
        $factory = new LLMClientFactory($this->ollama, $this->lmStudio, $this->gemini, 'lmstudio');

        $this->assertSame($this->lmStudio, $factory->create());
    }

    public function testCreatesGeminiClientWhenConfigured(): void
    {
        $factory = new LLMClientFactory($this->ollama, $this->lmStudio, $this->gemini, 'gemini');

        $this->assertSame($this->gemini, $factory->create());
    }

    public function testIsCaseInsensitive(): void
    {
        $factory = new LLMClientFactory($this->ollama, $this->lmStudio, $this->gemini, 'GEMINI');

        $this->assertSame($this->gemini, $factory->create());
    }

    public function testThrowsOnUnknownProvider(): void
    {
        $factory = new LLMClientFactory($this->ollama, $this->lmStudio, $this->gemini, 'claude');

        $this->expectException(\InvalidArgumentException::class);

        $factory->create();
    }
}
