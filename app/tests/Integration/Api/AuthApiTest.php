<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Firebase\JWT\JWT;
use Doctrine\DBAL\Connection;
use Symfony\Component\BrowserKit\Cookie;
use App\Tests\Integration\WebTestCaseBase;

/**
 * Authentification de l'API de bout en bout : connexion, jeton d'accès, rotation du cookie de rafraîchissement,
 * déconnexion, limitation du débit, avec le vrai noyau et le vrai pare-feu.
 */
final class AuthApiTest extends WebTestCaseBase
{
    private const string USER = 'admin';

    /** Le secret de test, tel que configuré dans `.env.test` (non dupliqué ici). */
    private function jwtSecret(): string
    {
        return (string) ($_SERVER['JOBSCAN_API_JWT_SECRET'] ?? $_ENV['JOBSCAN_API_JWT_SECRET'] ?? '');
    }

    /** @return array<mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function post(string $path, mixed $body = null, string $contentType = 'application/json'): void
    {
        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => $contentType], content: \is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function apiLogin(string $password = self::PASSWORD, string $username = self::USER): void
    {
        $this->post('/api/v1/auth/login', ['username' => $username, 'password' => $password]);
    }

    private function refreshCookie(): ?Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'jobscan_refresh') {
                return new Cookie($cookie->getName(), (string) $cookie->getValue(), null, $cookie->getPath(), 'localhost');
            }
        }

        return null;
    }

    private function rawRefreshToken(): string
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'jobscan_refresh') {
                return (string) $cookie->getValue();
            }
        }

        self::fail('Aucun cookie de rafraîchissement.');
    }

    private function sendCookie(string $value): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie('jobscan_refresh', $value, null, '/api/v1/auth', 'localhost'));
    }

    /** Connexion, puis retourne le jeton d'accès et le cookie de rafraîchissement en clair. @return array{string, string} */
    private function openSession(): array
    {
        $this->apiLogin();
        self::assertResponseStatusCodeSame(200);

        return [$this->json()['data']['access_token'], $this->rawRefreshToken()];
    }

    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    // --- Connexion ------------------------------------------------------------------------------

    public function testALoginOpensASessionWithTheContractedShape(): void
    {
        $this->apiLogin();

        self::assertResponseStatusCodeSame(200);
        $data = $this->json()['data'];
        self::assertSame('success', $this->json()['code']);
        self::assertSame(['id' => 'admin', 'username' => 'admin', 'roles' => ['ROLE_USER']], $data['user']);
        self::assertSame('Bearer', $data['token_type']);
        self::assertSame(900, $data['expires_in']);
        self::assertNotEmpty($data['access_token']);
        self::assertSame('no-store', $this->client->getResponse()->headers->getCacheControlDirective('no-store') ? 'no-store' : '');
        self::assertArrayNotHasKey('refresh_token', $data, 'Le jeton de rafraîchissement ne passe jamais par le corps.');
    }

    public function testTheRefreshCookieIsHardened(): void
    {
        $this->apiLogin();
        $cookies = array_values(array_filter($this->client->getResponse()->headers->getCookies(), static fn ($c): bool => $c->getName() === 'jobscan_refresh'));

        self::assertCount(1, $cookies);
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertSame('strict', $cookies[0]->getSameSite());
        self::assertSame('/api/v1/auth', $cookies[0]->getPath());
        self::assertGreaterThan(time() + 29 * 86400, $cookies[0]->getExpiresTime());
        self::assertCount(1, $this->client->getResponse()->headers->getCookies(), 'Aucune session PHP ni autre cookie.');
    }

    public function testWrongPasswordAndUnknownUserGetTheSameAnswer(): void
    {
        $this->apiLogin('mauvais');
        $wrongPassword = [$this->client->getResponse()->getStatusCode(), $this->json()];

        $this->apiLogin(self::PASSWORD, 'inconnu');
        $unknownUser = [$this->client->getResponse()->getStatusCode(), $this->json()];

        self::assertSame(401, $wrongPassword[0]);
        self::assertSame($wrongPassword, $unknownUser, 'La réponse ne révèle pas si l\'identifiant existe.');
        self::assertSame('invalid_credentials', $wrongPassword[1]['code']);
        self::assertNull($this->refreshCookie(), 'Un échec ne pose aucun cookie.');
    }

    public function testMalformedLoginRequestsAreRefusedBeforeAnyCheck(): void
    {
        $this->post('/api/v1/auth/login', ['username' => 'admin', 'password' => self::PASSWORD], 'application/x-www-form-urlencoded');
        self::assertResponseStatusCodeSame(415);

        foreach (['pas du json', '[]', '{"username":"admin"}', '{"username":1,"password":2}', '{"username":"","password":""}'] as $body) {
            $this->post('/api/v1/auth/login', $body);
            self::assertResponseStatusCodeSame(422, $body);
            self::assertFalse($this->json()['success']);
        }

        $this->post('/api/v1/auth/login', ['username' => 'admin', 'password' => str_repeat('a', 300)]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRepeatedFailuresAreThrottled(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->apiLogin('mauvais');
            self::assertResponseStatusCodeSame(401);
        }

        $this->apiLogin('mauvais');

        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('Trop de tentatives', $this->json()['data']['message']);

        $this->apiLogin(self::PASSWORD);
        self::assertResponseStatusCodeSame(429, 'Même le bon mot de passe est refusé pendant le blocage.');
    }

    public function testASuccessfulLoginResetsTheUserCounter(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->apiLogin('mauvais');
        }
        $this->apiLogin();
        self::assertResponseStatusCodeSame(200);

        for ($i = 0; $i < 4; ++$i) {
            $this->apiLogin('mauvais');
            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testWithoutASecretTheApiAuthenticationIsOffNotOpen(): void
    {
        $this->restartWithEnvironment('JOBSCAN_API_JWT_SECRET', '');

        $this->apiLogin();
        self::assertResponseStatusCodeSame(503);
        self::assertSame('auth_not_configured', $this->json()['code']);

        $this->post('/api/v1/auth/refresh', '');
        self::assertResponseStatusCodeSame(503);
    }

    public function testWithoutAConfiguredAccountNoOneCanLogIn(): void
    {
        $this->restartWithPasswordHash('');

        $this->apiLogin();

        self::assertResponseStatusCodeSame(503);
    }

    // --- Jeton d'accès --------------------------------------------------------------------------

    public function testTheAccessTokenOpensProtectedRoutes(): void
    {
        [$token] = $this->openSession();
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/v1/auth/me', server: $this->bearer($token));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('admin', $this->json()['data']['user']['username']);
    }

    public function testProtectedRoutesAnswerAJson401WithoutOrWithABadToken(): void
    {
        $this->client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthorized', $this->json()['code']);
        self::assertNull($this->client->getResponse()->headers->get('Location'), 'Jamais de redirection vers /login pour l\'API.');

        $this->client->request('GET', '/api/v1/auth/me', server: $this->bearer('pas-un-jeton'));
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));

        $this->client->request('GET', '/api/v1/auth/me', server: ['HTTP_AUTHORIZATION' => 'Basic YWRtaW46c2VjcmV0']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testExpiredAndForgedTokensAreRefused(): void
    {
        $expired = JWT::encode(['iss' => 'jobscan', 'sub' => 'admin', 'iat' => time() - 2000, 'exp' => time() - 1000], $this->jwtSecret(), 'HS256');
        $forged = JWT::encode(['iss' => 'jobscan', 'sub' => 'admin', 'exp' => time() + 900], str_repeat('b2', 20), 'HS256');
        $unknownUser = JWT::encode(['iss' => 'jobscan', 'sub' => 'fantome', 'exp' => time() + 900], $this->jwtSecret(), 'HS256');

        foreach ([$expired, $forged, $unknownUser] as $token) {
            $this->client->request('GET', '/api/v1/auth/me', server: $this->bearer($token));
            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testTheWebSessionDoesNotOpenTheApi(): void
    {
        $this->client->request('POST', '/login', ['_username' => self::USER, '_password' => self::PASSWORD]);

        $this->client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthRoutesRefuseOtherMethodsInTheEnvelope(): void
    {
        $this->client->request('GET', '/api/v1/auth/login');

        self::assertResponseStatusCodeSame(405);
        self::assertFalse($this->json()['success']);
    }

    // --- Rotation du jeton de rafraîchissement --------------------------------------------------

    public function testRefreshIssuesANewAccessTokenAndRotatesTheCookie(): void
    {
        [, $first] = $this->openSession();

        $this->post('/api/v1/auth/refresh', '');

        self::assertResponseStatusCodeSame(200);
        $second = $this->rawRefreshToken();
        self::assertNotSame($first, $second, 'Le cookie change à chaque rafraîchissement.');
        self::assertNotEmpty($this->json()['data']['access_token']);
        self::assertSame('admin', $this->json()['data']['user']['username']);

        $this->post('/api/v1/auth/refresh', '');
        self::assertResponseStatusCodeSame(200, 'La chaîne continue avec le nouveau cookie.');
    }

    public function testAReusedRefreshTokenRevokesTheWholeFamily(): void
    {
        [, $first] = $this->openSession();
        $this->post('/api/v1/auth/refresh', '');
        $second = $this->rawRefreshToken();

        $this->sendCookie($first);
        $this->post('/api/v1/auth/refresh', '');
        self::assertResponseStatusCodeSame(401, 'Un jeton déjà utilisé est refusé.');

        $this->sendCookie($second);
        $this->post('/api/v1/auth/refresh', '');
        self::assertResponseStatusCodeSame(401, 'Et son successeur aussi : le vol est neutralisé.');
    }

    public function testRefreshRefusesMissingUnknownAndExpiredTokensWithTheSameAnswer(): void
    {
        $this->client->getCookieJar()->clear();
        $this->post('/api/v1/auth/refresh', '');
        $missing = [$this->client->getResponse()->getStatusCode(), $this->json()];

        $this->sendCookie(bin2hex(random_bytes(32)));
        $this->post('/api/v1/auth/refresh', '');
        $unknown = [$this->client->getResponse()->getStatusCode(), $this->json()];
        $clearing = $this->client->getResponse()->headers->getCookies()[0] ?? null;

        [, $raw] = $this->openSession();
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE refresh_token SET expires_at = '2000-01-01 00:00:00'");
        $this->sendCookie($raw);
        $this->post('/api/v1/auth/refresh', '');
        $expired = [$this->client->getResponse()->getStatusCode(), $this->json()];

        self::assertSame(401, $missing[0]);
        self::assertSame($missing, $unknown);
        self::assertSame($missing, $expired);
        self::assertSame('', (string) $clearing?->getValue(), 'Un jeton inconnu efface le cookie.');
    }

    public function testOnlyTheHashOfARefreshTokenIsStored(): void
    {
        [, $raw] = $this->openSession();
        $stored = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT * FROM refresh_token');

        self::assertCount(1, $stored);
        self::assertSame(hash('sha256', (string) $raw), $stored[0]['token_hash']);
        self::assertStringNotContainsString($raw, (string) json_encode($stored));
    }

    public function testExpiredTokensAreDeletedAtTheNextLogin(): void
    {
        $this->openSession();
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("UPDATE refresh_token SET expires_at = '2000-01-01 00:00:00'");

        $this->openSession();

        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM refresh_token'));
    }

    public function testRefreshDoesNotSurviveAnAccountChange(): void
    {
        [, $raw] = $this->openSession();
        $this->restartWithEnvironment('JOBSCAN_UI_USER', 'autre');
        $this->entityManager->getConnection()->executeStatement('SELECT 1');

        $this->sendCookie($raw);
        $this->post('/api/v1/auth/refresh', '');

        self::assertResponseStatusCodeSame(401);
    }

    // --- Déconnexion ----------------------------------------------------------------------------

    public function testLogoutRevokesTheSessionAndClearsTheCookie(): void
    {
        [, $raw] = $this->openSession();

        $this->post('/api/v1/auth/logout', '');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->headers->getCookies()[0]->getValue());

        $this->sendCookie($raw);
        $this->post('/api/v1/auth/refresh', '');
        self::assertResponseStatusCodeSame(401, 'Après la déconnexion, le cookie ne rouvre plus rien.');
    }

    public function testLogoutRevokesTheLatestTokenOfTheFamilyToo(): void
    {
        $this->openSession();
        $this->post('/api/v1/auth/refresh', '');
        $latest = $this->rawRefreshToken();

        $this->post('/api/v1/auth/logout', '');
        $this->sendCookie($latest);
        $this->post('/api/v1/auth/refresh', '');

        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutIsIdempotent(): void
    {
        $this->client->getCookieJar()->clear();

        $this->post('/api/v1/auth/logout', '');
        self::assertResponseStatusCodeSame(204);

        $this->sendCookie('inconnu');
        $this->post('/api/v1/auth/logout', '');
        self::assertResponseStatusCodeSame(204);
    }

    // --- Derrière un proxy HTTPS (Traefik, ou le vôtre en production) ----------------------------------

    /** @return array<string, string> */
    private static function viaProxy(string $from = '172.18.0.9'): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $from, 'HTTP_HOST' => 'jobscan.local', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'jobscan.local', 'HTTP_X_FORWARDED_PORT' => '443'];
    }

    private function loginViaProxy(string $from = '172.18.0.9'): bool
    {
        $this->client->request('POST', '/api/v1/auth/login', server: self::viaProxy($from), content: (string) json_encode(['username' => self::USER, 'password' => self::PASSWORD]));
        self::assertResponseStatusCodeSame(200);

        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'jobscan_refresh') {
                return $cookie->isSecure();
            }
        }

        self::fail('Aucun cookie de rafraîchissement.');
    }

    public function testForwardedHeadersAreIgnoredByDefault(): void
    {
        self::assertFalse($this->loginViaProxy(), 'Sans proxy de confiance, X-Forwarded-Proto ne change rien : personne ne peut le falsifier.');
    }

    public function testTheRefreshCookieIsSecureBehindATrustedProxy(): void
    {
        $this->restartWithEnvironment('TRUSTED_PROXIES', 'REMOTE_ADDR', '172.18.0.9');

        self::assertTrue($this->loginViaProxy(), 'Régression : derrière le proxy HTTPS, le cookie n\'était pas « secure ».');
    }

    public function testOnlyTheConfiguredProxyIsTrusted(): void
    {
        $this->restartWithEnvironment('TRUSTED_PROXIES', '172.18.0.0/16');
        self::assertTrue($this->loginViaProxy('172.18.0.9'));

        $this->client->getCookieJar()->clear();
        self::assertFalse($this->loginViaProxy('203.0.113.7'), 'Un client extérieur au réseau du proxy ne peut pas imposer son schéma.');
    }

    // --- En-têtes de sécurité -------------------------------------------------------------------

    public function testEveryResponseCarriesTheSecurityHeaders(): void
    {
        foreach ([['GET', '/api/v1/jobs'], ['GET', '/api/v1/'], ['GET', '/health']] as [$method, $path]) {
            $this->client->request($method, $path);
            $headers = $this->client->getResponse()->headers;

            self::assertStringContainsString("default-src 'none'", (string) $headers->get('Content-Security-Policy'), $path);
            self::assertStringContainsString("frame-ancestors 'none'", (string) $headers->get('Content-Security-Policy'), $path);
            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
            self::assertSame('DENY', $headers->get('X-Frame-Options'), $path);
            self::assertSame('no-referrer', $headers->get('Referrer-Policy'), $path);
            self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'), $path);
        }
    }

    public function testNothingOutsideTheApiAndTheProbesIsReachableWithoutLoggingIn(): void
    {
        // L'ancienne interface Twig n'existe plus : ni page de connexion, ni écrans.
        foreach (['/login', '/job', '/applications', '/analytics'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }
}
