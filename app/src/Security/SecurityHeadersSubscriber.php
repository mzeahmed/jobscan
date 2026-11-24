<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * En-têtes de sécurité de toutes les réponses : politique de contenu (CSP) à nonce, anti-clickjacking, pas de sniffing,
 * pas de fuite de l'URL en `Referer`, pas de mise en cache des pages (elles contiennent des données personnelles).
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public const string NONCE_ATTRIBUTE = '_csp_nonce';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 100],
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::NONCE_ATTRIBUTE, bin2hex(random_bytes(16)));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $nonce = $event->getRequest()->attributes->get(self::NONCE_ATTRIBUTE);
        $headers = $event->getResponse()->headers;

        $headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'none'",
            sprintf("script-src 'self' 'nonce-%s'", is_string($nonce) ? $nonce : ''),
            "style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
            "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "img-src 'self' data:",
            "connect-src 'self'",
            "form-action 'self'",
            "base-uri 'none'",
            "object-src 'none'",
            "frame-ancestors 'none'",
        ]));
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Cache-Control', 'no-store, private');
    }
}
