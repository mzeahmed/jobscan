<?php

declare(strict_types=1);

namespace App\Provider;

use App\DTO\JobDto;

/**
 * Contrat commun à toutes les sources d'offres d'emploi.
 *
 * Chaque implémentation est taguée `app.job_provider` et injectée automatiquement
 * dans le pipeline via `!tagged_iterator`. Ajouter un nouveau provider ne nécessite
 * que d'implémenter cette interface — aucune modification du pipeline n'est requise.
 *
 * @see RsFeedProvider  Flux RSS et Atom
 * @see RemoteOkProvider API JSON RemoteOK
 * @see SearxProvider   Recherche web via SearXNG
 */
interface JobProviderInterface
{
    public function name(): string;

    /**
     * Indique si la source est joignable, via une sonde légère (pas un fetch complet).
     *
     * Utilisée par `RunPipelineCommand` au démarrage pour ignorer les providers
     * indisponibles sans annuler le run. Ne doit jamais propager d'exception :
     * toute erreur réseau se traduit par `false`.
     */
    public function isHealthy(): bool;

    /**
     * Récupère les offres d'emploi depuis la source.
     *
     * Les erreurs réseau ou de parsing doivent être absorbées en interne :
     * cette méthode ne doit jamais propager d'exception vers le pipeline.
     *
     * @return JobDto[]
     */
    public function fetch(): array;
}
