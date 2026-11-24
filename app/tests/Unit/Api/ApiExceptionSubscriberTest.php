<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use App\Api\Shared\ApiExceptionSubscriber;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ApiExceptionSubscriberTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $logged = [];

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string | \Stringable $message, array $context = []): void {
            $this->logged[] = [(string) $message, $context];
        });

        return $logger;
    }

    private function subscriber(bool $authenticated = false, bool $debug = false): ApiExceptionSubscriber
    {
        $tokens = new TokenStorage();
        if ($authenticated) {
            $user = new InMemoryUser('admin', null, ['ROLE_USER']);
            $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        return new ApiExceptionSubscriber($this->logger(), $tokens, $debug);
    }

    private function handle(\Throwable $throwable, string $path = '/api/v1/jobs', ?ApiExceptionSubscriber $subscriber = null, int $type = HttpKernelInterface::MAIN_REQUEST): ?Response
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create($path), $type, $throwable);
        ($subscriber ?? $this->subscriber())->onException($event);

        return $event->getResponse();
    }

    /** @return array<mixed> */
    private static function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testItRunsBeforeTheSecurityExceptionHandler(): void
    {
        $priority = ApiExceptionSubscriber::getSubscribedEvents()[KernelEvents::EXCEPTION][1];

        self::assertGreaterThan(1, $priority, 'La sécurité traite les exceptions à la priorité 1 : sans passer avant, un refus d\'accès serait redirigé.');
    }

    /** @return iterable<string, array{\Throwable, int, string}> */
    public static function httpExceptions(): iterable
    {
        yield '404' => [new NotFoundHttpException('No route found for "GET /api/v1/x"'), 404, 'not_found'];
        yield '400' => [new BadRequestHttpException('JSON mal formé : {'), 400, 'bad_request'];
        yield '403' => [new AccessDeniedHttpException('Interdit à jamais'), 403, 'forbidden'];
        yield '409' => [new ConflictHttpException('Doublon'), 409, 'conflict'];
        yield '415' => [new UnsupportedMediaTypeHttpException('text/xml'), 415, 'unsupported_media_type'];
        yield '422' => [new UnprocessableEntityHttpException('Champ invalide'), 422, 'unprocessable'];
        yield '503' => [new ServiceUnavailableHttpException(30, 'Maintenance'), 503, 'service_unavailable'];
    }

    #[DataProvider('httpExceptions')]
    public function testAnHttpExceptionKeepsItsStatusInTheEnvelope(\Throwable $exception, int $status, string $code): void
    {
        $response = $this->handle($exception);

        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        $body = self::body($response);
        self::assertSame($code, $body['code']);
        self::assertFalse($body['success']);
        self::assertNotSame('', $body['data']['message']);
        self::assertCount($status >= 500 ? 1 : 0, $this->logged, 'Une erreur client n\'est pas journalisée comme une erreur ; une erreur du serveur l\'est.');
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function httpExceptionsOnly(): iterable
    {
        foreach (self::httpExceptions() as $name => [$exception]) {
            yield $name => [$exception];
        }
    }

    #[DataProvider('httpExceptionsOnly')]
    public function testTheTextOfAnHttpExceptionIsNeverRelayed(\Throwable $exception): void
    {
        $response = $this->handle($exception);

        self::assertNotNull($response);
        self::assertStringNotContainsString($exception->getMessage(), (string) $response->getContent());
    }

    public function testAMethodNotAllowedRelaysTheAllowHeader(): void
    {
        $response = $this->handle(new MethodNotAllowedHttpException(['GET', 'HEAD'], 'interne'));

        self::assertSame(405, $response?->getStatusCode());
        self::assertSame('GET, HEAD', $response->headers->get('Allow'));
    }

    public function testTooManyRequestsRelaysRetryAfter(): void
    {
        $response = $this->handle(new TooManyRequestsHttpException(42));

        self::assertSame(429, $response?->getStatusCode());
        self::assertSame('42', $response->headers->get('Retry-After'));
    }

    public function testOnlyAllowAndRetryAfterAreRelayed(): void
    {
        $response = $this->handle(new NotFoundHttpException('x', null, 0, ['X-Secret' => 'interne', 'Set-Cookie' => 'a=b', 'allow' => 'GET']));

        self::assertNotNull($response);
        self::assertFalse($response->headers->has('X-Secret'));
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame('GET', $response->headers->get('Allow'), 'Le nom de l\'en-tête est reconnu sans tenir compte de la casse.');
    }

    public function testAnAuthenticationFailureIsA401NotARedirect(): void
    {
        $response = $this->handle(new AuthenticationException('jeton expiré'));

        self::assertSame(401, $response?->getStatusCode());
        self::assertSame('unauthorized', self::body($response)['code']);
        self::assertFalse($response->isRedirection());
    }

    public function testAccessDeniedIsA401ForAnAnonymousCaller(): void
    {
        $response = $this->handle(new AccessDeniedException());

        self::assertSame(401, $response?->getStatusCode());
        self::assertFalse($response->isRedirection(), 'Jamais de redirection vers la page de connexion sur /api.');
    }

    public function testAccessDeniedIsA403ForAnAuthenticatedCaller(): void
    {
        $response = $this->handle(new AccessDeniedException(), subscriber: $this->subscriber(authenticated: true));

        self::assertSame(403, $response?->getStatusCode());
        self::assertSame('forbidden', self::body($response)['code']);
    }

    public function testAnUnexpectedExceptionNeverLeaksItsMessage(): void
    {
        $secret = 'SQLSTATE[HY000]: unable to open database /srv/jobscan/var/jobscan.db';

        $response = $this->handle(new \RuntimeException($secret));

        self::assertSame(500, $response?->getStatusCode());
        $body = self::body($response);
        self::assertSame('internal_error', $body['code']);
        self::assertSame('Erreur interne du serveur.', $body['data']['message']);
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
        self::assertStringNotContainsString('jobscan.db', (string) $response->getContent());
        self::assertArrayNotHasKey('debug', $body['data']);
    }

    public function testAnUnexpectedExceptionIsLogged(): void
    {
        $this->handle(new \LogicException('détail'), '/api/v1/applications/3');

        self::assertCount(1, $this->logged);
        self::assertSame('api_exception', $this->logged[0][0]);
        self::assertSame(\LogicException::class, $this->logged[0][1]['exception']);
        self::assertSame('/api/v1/applications/3', $this->logged[0][1]['path']);
    }

    public function testDebugModeAddsTheExceptionToTheResponse(): void
    {
        $response = $this->handle(new \DomainException('boom'), subscriber: $this->subscriber(debug: true));

        self::assertSame(['exception' => \DomainException::class, 'message' => 'boom'], self::body($response ?? new Response('{}'))['data']['debug']);
    }

    public function testDebugModeNeverAddsDetailsToAClientError(): void
    {
        $response = $this->handle(new NotFoundHttpException('interne'), subscriber: $this->subscriber(debug: true));

        self::assertArrayNotHasKey('debug', self::body($response ?? new Response('{}'))['data']);
    }

    /** @return iterable<string, array{string}> */
    public static function nonApiPaths(): iterable
    {
        yield 'page de l\'interface' => ['/applications'];
        yield 'racine' => ['/'];
        yield 'sonde' => ['/health'];
        yield 'préfixe voisin' => ['/apix/v1'];
        yield 'préfixe non délimité' => ['/apiary'];
        yield 'API dans un sous-chemin' => ['/docs/api/v1'];
    }

    #[DataProvider('nonApiPaths')]
    public function testRoutesOutsideTheApiAreNotTouched(string $path): void
    {
        self::assertNull($this->handle(new NotFoundHttpException(), $path));
        self::assertNull($this->handle(new AccessDeniedException(), $path), 'Le comportement de la sécurité (redirection) est conservé.');
    }

    /** @return iterable<string, array{string}> */
    public static function apiPaths(): iterable
    {
        yield 'racine de l\'API' => ['/api/v1/'];
        yield 'sans barre' => ['/api'];
        yield 'avec barre' => ['/api/'];
        yield 'route' => ['/api/v1/applications/12'];
    }

    #[DataProvider('apiPaths')]
    public function testEveryApiPathIsHandled(string $path): void
    {
        self::assertSame(404, $this->handle(new NotFoundHttpException(), $path)?->getStatusCode());
    }

    public function testSubRequestsAreLeftAlone(): void
    {
        self::assertNull($this->handle(new NotFoundHttpException(), type: HttpKernelInterface::SUB_REQUEST));
    }
}
