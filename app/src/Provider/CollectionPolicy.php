<?php

declare(strict_types=1);

namespace App\Provider;

use App\Processor\DomainFilter;

/**
 * Restrictions de **collecte** : domaines que les providers de découverte web
 * (SearXNG, flux RSS) ne doivent pas récupérer, typiquement des plateformes dont
 * les conditions d'utilisation interdisent l'extraction automatisée.
 *
 * À distinguer des exclusions **métier** (`excluded_platform_domains`, appliquées
 * par `JobProcessor` à toute offre) : une plateforme peut être interdite de collecte
 * tout en restant une source légitime via ses emails d'alerte.
 */
final readonly class CollectionPolicy
{
    /**
     * @param array<array-key, mixed> $blockedDomains Config `app.profile.collection_blocked_domains`
     */
    public function __construct(private array $blockedDomains = [])
    {
    }

    public function allows(string $url): bool
    {
        return !DomainFilter::matches($url, $this->blockedDomains);
    }
}
