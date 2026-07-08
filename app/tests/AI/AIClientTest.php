<?php

declare(strict_types=1);

namespace App\Tests\AI;

use App\DTO\Seniority;
use Psr\Log\NullLogger;
use App\AI\AIClient;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use App\AI\Provider\AIProviderInterface;

class AIClientTest extends TestCase
{
    private const array KNOWN_STACK = ['php', 'symfony', 'wordpress', 'mysql', 'react'];

    private AIProviderInterface $provider;
    private CacheItemPoolInterface $cache;
    private CacheItemInterface $cacheItem;

    protected function setUp(): void
    {
        $this->provider = $this->createStub(AIProviderInterface::class);
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
        $this->provider->method('complete')->willReturn(null);

        $client = $this->makeClient();
        $result = $client->analyze('Développeur PHP Symfony senior remote freelance 500€/j');

        $this->assertSame(ContractType::Freelance, $result->contractType);
        $this->assertTrue($result->freelance);
        $this->assertTrue($result->remote);
        $this->assertSame(Seniority::Senior, $result->seniority);
        $this->assertContains('php', $result->stack);
        $this->assertContains('symfony', $result->stack);
        $this->assertSame('500€/j', $result->budget);
    }

    public function testHeuristicFallbackDetectsCdi(): void
    {
        $this->provider->method('complete')->willReturn(null);

        $result = $this->makeClient()->analyze('Poste CDI développeur backend Paris');

        $this->assertSame(ContractType::Cdi, $result->contractType);
        $this->assertFalse($result->freelance);
    }

    public function testHeuristicFallbackDetectsJunior(): void
    {
        $this->provider->method('complete')->willReturn(null);

        $result = $this->makeClient()->analyze('Développeur PHP junior débutant accepté');

        $this->assertSame(Seniority::Junior, $result->seniority);
    }

    public function testHeuristicFallbackExtractsBudgetRange(): void
    {
        $this->provider->method('complete')->willReturn(null);

        $result = $this->makeClient()->analyze('Salaire 60-80k selon profil');

        $this->assertSame('60-80k€/an', $result->budget);
    }

    public function testHeuristicFallbackReturnsNonPreciseWhenNoBudget(): void
    {
        $this->provider->method('complete')->willReturn(null);

        $result = $this->makeClient()->analyze('Mission PHP sans précision de budget');

        $this->assertSame('non précisé', $result->budget);
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

        $this->provider->method('complete')->willReturn($json);

        $result = $this->makeClient()->analyze('Mission PHP Symfony remote senior');

        $this->assertSame(['php', 'symfony'], $result->stack);
        $this->assertSame(ContractType::Freelance, $result->contractType);
        $this->assertTrue($result->remote);
        $this->assertSame('600€/j', $result->budget);
        $this->assertSame(Seniority::Senior, $result->seniority);
    }

    public function testParsesJsonEmbeddedInText(): void
    {
        $json = 'Voici ma réponse : {"stack":["react"],"contract_type":"cdi","freelance":false,"remote":false,"budget":"non précisé","recent":true,"seniority":"mid"} fin.';

        $this->provider->method('complete')->willReturn($json);

        $result = $this->makeClient()->analyze('Développeur React CDI');

        $this->assertSame(['react'], $result->stack);
        $this->assertSame(ContractType::Cdi, $result->contractType);
        $this->assertSame(Seniority::Mid, $result->seniority);
    }

    public function testFallsBackWhenAIReturnsUnparseableContent(): void
    {
        $this->provider->method('complete')->willReturn('Je ne sais pas répondre en JSON désolé.');

        $result = $this->makeClient()->analyze('Mission PHP Symfony freelance remote');

        $this->assertInstanceOf(AiAnalysisDto::class, $result);
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

        $this->provider->method('complete')->willReturn($json);

        $result = $this->makeClient()->analyze('Poste CDD générique');

        $this->assertSame(ContractType::Unknown, $result->contractType);
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

        $this->provider->method('complete')->willReturn($json);

        $result = $this->makeClient()->analyze('Poste CDI expert');

        $this->assertSame(Seniority::Unknown, $result->seniority);
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    public function testReturnsCachedResultWithoutCallingAI(): void
    {
        $cached = new AiAnalysisDto(
            stack: ['php'],
            contractType: ContractType::Freelance,
            freelance: true,
            remote: true,
            budget: '500€/j',
            recent: true,
            seniority: Seniority::Senior,
        );

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($cached);

        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);

        $provider = $this->createMock(AIProviderInterface::class);
        $provider->expects($this->never())->method('complete');

        $client = new AIClient(
            $provider,
            new NullLogger(),
            $cache,
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
            $this->provider,
            new NullLogger(),
            $this->cache,
            'system prompt',
            self::KNOWN_STACK,
        );
    }
}
