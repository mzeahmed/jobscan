<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Identity;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use App\Api\Identity\Token\AccessTokenService;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AccessTokenServiceTest extends TestCase
{
    private string $secret;
    private string $otherSecret;
    private MockClock $clock;
    private AccessTokenService $service;

    protected function setUp(): void
    {
        // Construits à l'exécution : aucun littéral ressemblant à un secret dans le dépôt (gitleaks).
        $this->secret = str_repeat('a1', 20);
        $this->otherSecret = str_repeat('b2', 20);
        $this->clock = new MockClock('2026-12-10 10:00:00');
        $this->service = new AccessTokenService($this->secret, 900, $this->clock);
    }

    private function user(): InMemoryUser
    {
        return new InMemoryUser('admin', null, ['ROLE_USER']);
    }

    public function testAnIssuedTokenIsVerifiedBackToItsUser(): void
    {
        self::assertSame('admin', $this->service->verify($this->service->issue($this->user())));
    }

    public function testATokenExpiresAfterItsLifetimeAndNotBefore(): void
    {
        $token = $this->service->issue($this->user());

        $this->clock->sleep(899);
        self::assertSame('admin', $this->service->verify($token));

        $this->clock->sleep(2);
        self::assertNull($this->service->verify($token));
    }

    public function testATokenSignedWithAnotherSecretIsRefused(): void
    {
        $forged = JWT::encode(['iss' => 'jobscan', 'sub' => 'admin', 'exp' => $this->clock->now()->getTimestamp() + 900], $this->otherSecret, 'HS256');

        self::assertNull($this->service->verify($forged));
    }

    public function testAnUnsignedTokenIsRefused(): void
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $none = $encode(['alg' => 'none', 'typ' => 'JWT']) . '.' . $encode(['iss' => 'jobscan', 'sub' => 'admin', 'exp' => $this->clock->now()->getTimestamp() + 900]) . '.';

        self::assertNull($this->service->verify($none));
    }

    public function testATamperedPayloadIsRefused(): void
    {
        [$header, , $signature] = explode('.', $this->service->issue($this->user()));
        $payload = rtrim(strtr(base64_encode((string) json_encode(['iss' => 'jobscan', 'sub' => 'root', 'exp' => $this->clock->now()->getTimestamp() + 900])), '+/', '-_'), '=');

        self::assertNull($this->service->verify("$header.$payload.$signature"));
    }

    public function testATokenFromAnotherIssuerIsRefused(): void
    {
        $foreign = JWT::encode(['iss' => 'ailleurs', 'sub' => 'admin', 'exp' => $this->clock->now()->getTimestamp() + 900], $this->secret, 'HS256');

        self::assertNull($this->service->verify($foreign));
    }

    public function testGarbageIsRefused(): void
    {
        foreach (['', 'abc', 'a.b.c', '....'] as $garbage) {
            self::assertNull($this->service->verify($garbage));
        }
    }

    public function testAShortOrEmptySecretDisablesTheServiceInsteadOfOpeningIt(): void
    {
        foreach (['', 'court'] as $secret) {
            $service = new AccessTokenService($secret, 900, $this->clock);

            self::assertFalse($service->isConfigured());
            self::assertNull($service->verify($this->service->issue($this->user())));
        }
    }

    public function testTheSessionBodyExposesNoSecret(): void
    {
        $session = $this->service->session($this->user());

        self::assertSame(['id' => 'admin', 'username' => 'admin', 'roles' => ['ROLE_USER']], $session['user']);
        self::assertSame('Bearer', $session['token_type']);
        self::assertSame(900, $session['expires_in']);
        self::assertStringNotContainsString($this->secret, (string) json_encode($session));
    }
}
