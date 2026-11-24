<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Toute erreur d'une route `/api/…` sort dans l'enveloppe JSON de l'API, jamais en page HTML ni en redirection.
 *
 * - une exception HTTP (404, 405, 429…) garde son statut ; seuls `Allow` et `Retry-After` sont relayés ;
 * - un refus d'accès donne 401 sans utilisateur authentifié, 403 sinon (au lieu de la redirection vers la connexion) ;
 * - toute autre exception donne 500 avec un message générique : son texte n'est **jamais** renvoyé au client
 *   (il peut contenir une requête SQL, un chemin, un secret). Il est journalisé ; en mode debug seulement,
 *   la classe et le message sont ajoutés à la réponse.
 *
 * Priorité 2 : avant le gestionnaire de la sécurité (1), pour qu'un refus d'accès sur `/api` ne soit pas redirigé.
 * Les routes hors `/api` ne sont pas touchées.
 */
final readonly class ApiExceptionSubscriber implements EventSubscriberInterface
{
    private const array RELAYED_HEADERS = ['Allow', 'Retry-After'];

    public function __construct(
        private LoggerInterface $logger,
        private TokenStorageInterface $tokens,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 2]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isApiPath($request->getPathInfo())) {
            return;
        }

        $throwable = $event->getThrowable();
        [$status, $headers] = $this->statusAndHeaders($throwable);
        $data = [];

        if ($status >= 500) {
            $this->logger->error('api_exception', ['exception' => $throwable::class, 'message' => $throwable->getMessage(), 'path' => $request->getPathInfo()]);
            if ($this->debug) {
                $data['debug'] = ['exception' => $throwable::class, 'message' => $throwable->getMessage()];
            }
        }

        $event->setResponse(ApiResponse::error($data, $status, $headers));
    }

    private static function isApiPath(string $path): bool
    {
        return '/api' === $path || str_starts_with($path, '/api/');
    }

    /** @return array{int, array<string, string>} */
    private function statusAndHeaders(\Throwable $throwable): array
    {
        return match (true) {
            $throwable instanceof AuthenticationException => [401, []],
            $throwable instanceof AccessDeniedException => [$this->isAuthenticated() ? 403 : 401, []],
            $throwable instanceof HttpExceptionInterface => [$throwable->getStatusCode(), self::relayedHeaders($throwable)],
            default => [500, []],
        };
    }

    private function isAuthenticated(): bool
    {
        return $this->tokens->getToken()?->getUser() instanceof UserInterface;
    }

    /** @return array<string, string> */
    private static function relayedHeaders(HttpExceptionInterface $exception): array
    {
        $headers = [];
        foreach ($exception->getHeaders() as $name => $value) {
            foreach (self::RELAYED_HEADERS as $allowed) {
                if (0 === strcasecmp($allowed, (string) $name)) {
                    $headers[$allowed] = \is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value;
                }
            }
        }

        return $headers;
    }
}
