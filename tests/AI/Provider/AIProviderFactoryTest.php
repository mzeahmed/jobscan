<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use App\AI\Provider\GeminiProvider;
use App\AI\Provider\AIProviderFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use App\AI\Provider\OpenAICompatibleProvider;

class AIProviderFactoryTest extends TestCase
{
    private OpenAICompatibleProvider $openAiCompatible;
    private GeminiProvider $gemini;

    protected function setUp(): void
    {
        $httpClient = new MockHttpClient();
        $this->openAiCompatible = new OpenAICompatibleProvider($httpClient, new NullLogger(), 'http://localhost:11434/v1', 'ollama', 'qwen3:8b');
        $this->gemini = new GeminiProvider($httpClient, new NullLogger(), 'api-key', 'gemini-2.0-flash');
    }

    public function testCreatesOllamaProviderByDefault(): void
    {
        $factory = new AIProviderFactory($this->openAiCompatible, $this->gemini, 'ollama');

        $this->assertSame($this->openAiCompatible, $factory->create());
    }

    public function testCreatesGeminiProviderWhenConfigured(): void
    {
        $factory = new AIProviderFactory($this->openAiCompatible, $this->gemini, 'gemini');

        $this->assertSame($this->gemini, $factory->create());
    }

    public function testIsCaseInsensitive(): void
    {
        $factory = new AIProviderFactory($this->openAiCompatible, $this->gemini, 'GEMINI');

        $this->assertSame($this->gemini, $factory->create());
    }

    public function testThrowsOnUnknownProvider(): void
    {
        $factory = new AIProviderFactory($this->openAiCompatible, $this->gemini, 'claude');

        $this->expectException(\InvalidArgumentException::class);

        $factory->create();
    }
}
