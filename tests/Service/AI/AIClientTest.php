<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Service\AI\AIClient;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AIClientTest extends TestCase
{
    private const array KNOWN_STACK = ['php', 'symfony', 'wordpress', 'mysql', 'react'];

    private HttpClientInterface $httpClient;
    private CacheItemPoolInterface $cache;
    private CacheItemInterface $cacheItem;

    protected function setUp(): void
    {
        $this->httpClient = $this->createStub(HttpClientInterface::class);
        $this->cacheItem = $this->createStub(CacheItemInterface::class);
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cacheItem->method('set')->willReturnSelf();

        $this->cache = $this->createStub(CacheItemPoolInterface::class);
        $this->cache->method('getItem')->willReturn($this->cacheItem);
        $this->cache->method('save')->willReturn(true);
    }

    // -------------------------------------------------------------------------
    // Fallback heuristique
    // -------------------------------------------------------------------------

    public function testHeuristicFallbackUsedWhenAIFails(): void
    {
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('Ollama unreachable'));

        $client = $this->makeClient();
        $result = $client->analyze('Développeur PHP Symfony senior remote freelance 500€/j');

        $this->assertSame('freelance', $result['contract_type']);
        $this->assertTrue($result['freelance']);
        $this->assertTrue($result['remote']);
        $this->assertSame('senior', $result['seniority']);
        $this->assertContains('php', $result['stack']);
        $this->assertContains('symfony', $result['stack']);
        $this->assertSame('500€/j', $result['budget']);
    }

    public function testHeuristicFallbackDetectsCdi(): void
    {
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('unavailable'));

        $result = $this->makeClient()->analyze('Poste CDI développeur backend Paris');

        $this->assertSame('cdi', $result['contract_type']);
        $this->assertFalse($result['freelance']);
    }

    public function testHeuristicFallbackDetectsJunior(): void
    {
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('unavailable'));

        $result = $this->makeClient()->analyze('Développeur PHP junior débutant accepté');

        $this->assertSame('junior', $result['seniority']);
    }

    public function testHeuristicFallbackExtractsBudgetRange(): void
    {
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('unavailable'));

        $result = $this->makeClient()->analyze('Salaire 60-80k selon profil');

        $this->assertSame('60-80k€/an', $result['budget']);
    }

    public function testHeuristicFallbackReturnsNonPreciseWhenNoBudget(): void
    {
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('unavailable'));

        $result = $this->makeClient()->analyze('Mission PHP sans précision de budget');

        $this->assertSame('non précisé', $result['budget']);
    }

    // -------------------------------------------------------------------------
    // Parsing réponse IA
    // -------------------------------------------------------------------------

    public function testParsesValidJsonResponse(): void
    {
        $json = json_encode([
            'stack' => ['php', 'symfony'],
            'contract_type' => 'freelance',
            'freelance' => true,
            'remote' => true,
            'budget' => '600€/j',
            'recent' => true,
            'seniority' => 'senior',
        ]);

        $this->mockHttpResponse($json);

        $result = $this->makeClient()->analyze('Mission PHP Symfony remote senior');

        $this->assertSame(['php', 'symfony'], $result['stack']);
        $this->assertSame('freelance', $result['contract_type']);
        $this->assertTrue($result['remote']);
        $this->assertSame('600€/j', $result['budget']);
        $this->assertSame('senior', $result['seniority']);
    }

    public function testParsesJsonEmbeddedInText(): void
    {
        $json = 'Voici ma réponse : {"stack":["react"],"contract_type":"cdi","freelance":false,"remote":false,"budget":"non précisé","recent":true,"seniority":"mid"} fin.';

        $this->mockHttpResponse($json);

        $result = $this->makeClient()->analyze('Développeur React CDI');

        $this->assertSame(['react'], $result['stack']);
        $this->assertSame('cdi', $result['contract_type']);
        $this->assertSame('mid', $result['seniority']);
    }

    public function testFallsBackWhenAIReturnsUnparseableContent(): void
    {
        $this->mockHttpResponse('Je ne sais pas répondre en JSON désolé.');

        $result = $this->makeClient()->analyze('Mission PHP Symfony freelance remote');

        // Should fall back to heuristic — check key fields exist
        $this->assertArrayHasKey('stack', $result);
        $this->assertArrayHasKey('contract_type', $result);
        $this->assertArrayHasKey('freelance', $result);
        $this->assertArrayHasKey('remote', $result);
        $this->assertArrayHasKey('budget', $result);
        $this->assertArrayHasKey('recent', $result);
        $this->assertArrayHasKey('seniority', $result);
    }

    public function testNormalizesUnknownContractType(): void
    {
        $json = json_encode([
            'stack' => [],
            'contract_type' => 'cdd',
            'freelance' => false,
            'remote' => false,
            'budget' => 'non précisé',
            'recent' => false,
            'seniority' => 'unknown',
        ]);

        $this->mockHttpResponse($json);

        $result = $this->makeClient()->analyze('Poste CDD générique');

        $this->assertSame('unknown', $result['contract_type']);
    }

    public function testNormalizesUnknownSeniority(): void
    {
        $json = json_encode([
            'stack' => [],
            'contract_type' => 'cdi',
            'freelance' => false,
            'remote' => false,
            'budget' => 'non précisé',
            'recent' => false,
            'seniority' => 'expert',
        ]);

        $this->mockHttpResponse($json);

        $result = $this->makeClient()->analyze('Poste CDI expert');

        $this->assertSame('unknown', $result['seniority']);
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    public function testReturnsCachedResultWithoutCallingAI(): void
    {
        $cached = [
            'stack' => ['php'],
            'contract_type' => 'freelance',
            'freelance' => true,
            'remote' => true,
            'budget' => '500€/j',
            'recent' => true,
            'seniority' => 'senior',
        ];

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($cached);

        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);

        $httpClient = $this->createStub(HttpClientInterface::class);

        $client = new AIClient(
            $httpClient,
            new NullLogger(),
            $cache,
            'http://localhost:11434/v1',
            'ollama',
            'llama3.1:8b',
            'system prompt',
            self::KNOWN_STACK,
        );

        $result = $client->analyze('Développeur PHP');

        $this->assertSame($cached, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeClient(): AIClient
    {
        return new AIClient(
            $this->httpClient,
            new NullLogger(),
            $this->cache,
            'http://localhost:11434/v1',
            'ollama',
            'llama3.1:8b',
            'system prompt',
            self::KNOWN_STACK,
        );
    }

    private function mockHttpResponse(string $content): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'choices' => [
                ['message' => ['content' => $content]],
            ],
        ]);

        $this->httpClient->method('request')->willReturn($response);
    }
}
