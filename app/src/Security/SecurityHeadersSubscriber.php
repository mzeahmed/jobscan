<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * En-têtes de sécurité de toutes les réponses (JSON de l'API et sondes) : aucun contenu actif n'est jamais chargé
 * depuis une réponse de l'API (CSP `default-src 'none'`), pas d'affichage dans un cadre, pas de sniffing, pas de fuite
 * de l'URL en `Referer`, pas de mise en cache (les réponses contiennent des données personnelles).
 *
 * La politique de contenu de l'écran React est posée par nginx (voir `.docker/nginx/prod.conf`).
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        $headers->set('Content-Security-Policy', "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Cache-Control', 'no-store, private');
    }
}
