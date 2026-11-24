<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'API de bout en bout : routage, accès public, enveloppe et erreurs, avec le vrai noyau et le vrai pare-feu.
 */
final class ApiFoundationTest extends WebTestCase
{
    private AbstractBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @return array<mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function contentType(): string
    {
        return (string) $this->client->getResponse()->headers->get('Content-Type');
    }

    // --- Sondes ----------------------------------------------------------------------------------

    public function testTheLivenessProbeIsPublicAndPlain(): void
    {
        $this->client->request('GET', '/health');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ok', 'service' => 'jobscan'], $this->json(), 'Sans enveloppe : une sonde est lue par une machine.');
        self::assertStringContainsString('application/json', $this->contentType());
    }

    public function testTheReadinessProbeChecksTheDatabase(): void
    {
        $this->client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ready', 'dependencies' => ['database' => true]], $this->json());
    }

    public function testProbesStartNoSessionAndSetNoCookie(): void
    {
        $this->client->request('GET', '/health');

        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Une sonde appelée toutes les dix secondes ne doit pas créer de session.');
    }

    public function testProbesCarryTheSecurityHeaders(): void
    {
        $this->client->request('GET', '/health');
        $headers = $this->client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
    }

    public function testProbesAreOnlyReadable(): void
    {
        $this->client->request('POST', '/health');

        self::assertResponseStatusCodeSame(405);
    }

    // --- Racine de l'API -------------------------------------------------------------------------

    public function testTheApiRootIsPublicAndUsesTheEnvelope(): void
    {
        $this->client->request('GET', '/api/v1/');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['code' => 'success', 'success' => true, 'data' => ['service' => 'jobscan-api', 'version' => 'v1', 'message' => 'OK']],
            $this->json(),
        );
        self::assertStringContainsString('application/json', $this->contentType());
    }

    public function testTheApiRootIsNotRedirectedToTheLoginPage(): void
    {
        $this->client->request('GET', '/api/v1/');

        self::assertFalse($this->client->getResponse()->isRedirection());
    }

    // --- Erreurs ---------------------------------------------------------------------------------

    public function testAnUnknownApiRouteIsAJson404InTheEnvelope(): void
    {
        $this->client->request('GET', '/api/v1/inconnue');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->json()['code']);
        self::assertFalse($this->json()['success']);
        self::assertSame('Ressource introuvable.', $this->json()['data']['message']);
        self::assertStringContainsString('application/json', $this->contentType());
    }

    public function testTheInternalReasonOfA404IsNotExposed(): void
    {
        $this->client->request('GET', '/api/v1/inconnue');

        self::assertStringNotContainsString('No route found', (string) $this->client->getResponse()->getContent());
    }

    public function testAnApiErrorIsJsonWhateverTheClientAsksFor(): void
    {
        $this->client->request('GET', '/api/v1/inconnue', server: ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('application/json', $this->contentType());
    }

    public function testAWrongMethodIsAJson405WithTheAllowHeader(): void
    {
        $this->client->request('POST', '/api/v1/');

        self::assertResponseStatusCodeSame(405);
        self::assertSame('method_not_allowed', $this->json()['code']);
        self::assertStringContainsString('GET', (string) $this->client->getResponse()->headers->get('Allow'));
    }

    public function testErrorsCarryTheSecurityHeaders(): void
    {
        $this->client->request('GET', '/api/v1/inconnue');
        $headers = $this->client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
    }

    // --- Ce qui ne doit pas changer ----------------------------------------------------------------

    public function testThePagesOfTheWebInterfaceStillRequireAuthentication(): void
    {
        foreach (['/job', '/applications', '/analytics'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseRedirects('http://localhost/login', null, $path);
        }
    }

    public function testTheLoginPageStillWorks(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('text/html', $this->contentType());
    }

    public function testErrorsOutsideTheApiAreNotTurnedIntoJson(): void
    {
        $this->client->request('GET', '/page-inexistante');

        self::assertStringNotContainsString('"success"', (string) $this->client->getResponse()->getContent());
    }

    public function testOnlyTheProbesAndTheApiRootArePublic(): void
    {
        foreach (['/health', '/health/ready', '/api/v1/', '/login'] as $path) {
            $this->client->request('GET', $path);

            self::assertFalse($this->client->getResponse()->isRedirection(), $path . ' doit rester public.');
        }

        // Une route d'API qui n'existe pas n'est pas « publique » pour autant : elle ne révèle rien, c'est tout.
        $this->client->request('GET', '/api/v1/applications');
        self::assertResponseStatusCodeSame(404);
    }
}
