<?php

declare(strict_types=1);

namespace App\Security;

use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\Extension\AbstractExtension;
use Symfony\Component\HttpFoundation\RequestStack;

final class UiExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requests)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('csp_nonce', $this->nonce(...))];
    }

    #[\Override]
    public function getFilters(): array
    {
        return [new TwigFilter('safe_url', self::safeUrl(...))];
    }

    public function nonce(): string
    {
        $nonce = $this->requests->getMainRequest()?->attributes->get(SecurityHeadersSubscriber::NONCE_ATTRIBUTE);

        return is_string($nonce) ? $nonce : '';
    }

    /**
     * Une URL d'annonce n'est cliquable que si c'est une URL `http(s)` : une annonce piégée ne peut pas y mettre `javascript:`.
     */
    public static function safeUrl(?string $url): string
    {
        $url = trim((string) $url);

        return preg_match('~^https?://[^\s<>"\']+$~i', $url) === 1 ? $url : '#';
    }
}
