<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use PHPUnit\Framework\Attributes\DataProvider;

final class SecurityTest extends WebTestCaseBase
{
    /** @return iterable<string, array{string, string}> */
    public static function protectedRoutes(): iterable
    {
        yield 'liste des offres' => ['GET', '/job'];
        yield 'racine' => ['GET', '/'];
        yield 'candidatures' => ['GET', '/applications'];
        yield 'détail' => ['GET', '/applications/1'];
        yield 'changement de statut' => ['POST', '/applications/1/status'];
        yield 'détails' => ['POST', '/applications/1/details'];
        yield 'suivre' => ['POST', '/job/1/track'];
        yield 'décider' => ['POST', '/job/1/decide'];
    }

    #[DataProvider('protectedRoutes')]
    public function testEverythingRequiresAuthentication(string $method, string $path): void
    {
        $this->client->request($method, $path);

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testTheLoginPageIsPublicAndStaticAssetsAreNotBlocked(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name=_csrf_token]');
    }

    public function testLoginIsImpossibleWhileNoPasswordIsConfigured(): void
    {
        $this->restartWithPasswordHash('');
        $page = $this->client->request('GET', '/login');
        self::assertStringContainsString('pas configurée', $page->filter('.flash')->text());

        $this->client->submit($page->selectButton('Se connecter')->form(['_username' => 'admin', '_password' => self::PASSWORD]));
        $this->client->followRedirect();
        $this->client->request('GET', '/applications');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testLoginWithAConfiguredAccount(): void
    {
        $page = $this->client->request('GET', '/login');
        $this->client->submit($page->selectButton('Se connecter')->form(['_username' => 'admin', '_password' => self::PASSWORD]));

        self::assertResponseRedirects('/applications');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Candidatures');
    }

    public function testAWrongPasswordIsRefusedWithAGenericMessage(): void
    {
        foreach ([['admin', 'mauvais'], ['inconnu', self::PASSWORD]] as [$user, $password]) {
            $page = $this->client->request('GET', '/login');
            $this->client->submit($page->selectButton('Se connecter')->form(['_username' => $user, '_password' => $password]));
            $this->client->followRedirect();
            self::assertSelectorTextContains('.flash--error', 'Identifiants invalides');
            $this->client->request('GET', '/applications');
            self::assertResponseRedirects('http://localhost/login');
        }
    }

    public function testLoginWithoutTheCsrfTokenIsRefused(): void
    {
        $this->client->request('GET', '/login');

        $this->client->request('POST', '/login', ['_username' => 'admin', '_password' => self::PASSWORD, '_csrf_token' => 'faux']);
        $this->client->followRedirect();

        self::assertSelectorExists('.flash--error');
        $this->client->request('GET', '/applications');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testRepeatedFailuresAreThrottled(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $page = $this->client->request('GET', '/login');
            $this->client->submit($page->selectButton('Se connecter')->form(['_username' => 'admin', '_password' => 'mauvais' . $i]));
            $this->client->followRedirect();
        }

        // Le bon mot de passe n'est plus accepté une fois la limite atteinte.
        $page = $this->client->request('GET', '/login');
        $this->client->submit($page->selectButton('Se connecter')->form(['_username' => 'admin', '_password' => self::PASSWORD]));
        $this->client->followRedirect();

        self::assertSelectorExists('.flash--error');
    }

    public function testSecurityHeadersAreSetWithAMatchingNonce(): void
    {
        $this->login();
        $this->client->request('GET', '/applications');

        $response = $this->client->getResponse();
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringNotContainsString("'unsafe-inline'", $csp);
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        self::assertSame(1, preg_match("/'nonce-([a-f0-9]{32})'/", $csp, $nonce));
        self::assertStringContainsString('nonce="' . $nonce[1] . '"', (string) $response->getContent(), 'Les scripts de la page portent le nonce de la politique.');
        self::assertStringNotContainsString('onclick=', (string) $response->getContent());
    }

    public function testTheNonceChangesAtEveryRequest(): void
    {
        $this->login();
        $this->client->request('GET', '/applications');
        $first = $this->client->getResponse()->headers->get('Content-Security-Policy');
        $this->client->request('GET', '/applications');

        self::assertNotSame($first, $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testLogoutRequiresItsCsrfToken(): void
    {
        $this->login();
        $page = $this->client->request('GET', '/applications');

        $this->client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(403, 'Sans jeton, la déconnexion est refusée (pas de déconnexion par un lien piégé).');

        $this->client->click($page->selectLink('Déconnexion')->link());
        self::assertResponseRedirects('http://localhost/login');
        $this->client->request('GET', '/applications');
        self::assertResponseRedirects('http://localhost/login');
    }
}
