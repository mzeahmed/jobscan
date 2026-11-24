<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant;

use App\Assistant\LlmLocality;
use App\Assistant\PrivacyGate;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LlmLocalityTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, bool}> */
    public static function engines(): iterable
    {
        yield 'ollama local' => ['ollama', 'http://localhost:11434/v1', true];
        yield 'boucle locale IPv4' => ['ollama', 'http://127.0.0.1:11434/v1', true];
        yield 'boucle locale IPv6' => ['ollama', 'http://[::1]:11434/v1', true];
        yield 'hôte Docker' => ['ollama', 'http://host.docker.internal:11434/v1', true];
        yield 'LM Studio en réseau privé' => ['lmstudio', 'http://192.168.1.20:1234/v1', true];
        yield 'réseau 10.x' => ['lmstudio', 'http://10.0.0.5:1234/v1', true];
        yield 'ollama sur Internet' => ['ollama', 'https://ollama.example.com/v1', false];
        yield 'IP publique' => ['ollama', 'http://8.8.8.8:11434/v1', false];
        yield 'nom d\'hôte inconnu' => ['ollama', 'http://gpu-server:11434/v1', false];
        yield 'gemini' => ['gemini', 'http://localhost', false];
        yield 'gemini en majuscules' => ['GEMINI', null, false];
        yield 'adresse absente' => ['ollama', null, false];
        yield 'adresse invalide' => ['ollama', 'pas une url', false];
        yield 'domaine imitant localhost' => ['ollama', 'http://localhost.evil.example/v1', false];
    }

    #[DataProvider('engines')]
    public function testLocality(string $provider, ?string $url, bool $local): void
    {
        self::assertSame($local, LlmLocality::isLocal($provider, $url));
    }

    public function testRemoteEnginesNeedAnExplicitAuthorization(): void
    {
        $urls = ['ollama' => 'https://ollama.example.com/v1'];

        self::assertFalse(new PrivacyGate('ollama', $urls, false)->allowsProfileData());
        self::assertTrue(new PrivacyGate('ollama', $urls, true)->allowsProfileData());
        self::assertFalse(new PrivacyGate('gemini', [], false)->allowsProfileData());
        self::assertTrue(new PrivacyGate('ollama', ['ollama' => 'http://localhost:11434/v1'], false)->allowsProfileData());
        self::assertSame('moteur gemini (distant)', new PrivacyGate('gemini', [], true)->describe());
    }
}
