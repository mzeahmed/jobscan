<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\DTO\JobDTO;

/**
 * Contrat commun à toutes les sources d'offres d'emploi.
 *
 * Chaque implémentation est taguée `app.job_provider` et injectée automatiquement
 * dans le pipeline via `!tagged_iterator`. Ajouter un nouveau provider ne nécessite
 * que d'implémenter cette interface — aucune modification du pipeline n'est requise.
 *
 * @see RsFeedProvider  Flux RSS et Atom
 * @see SearxProvider   Recherche web via SearXNG
 */
interface JobProviderInterface
{
    /**
     * Récupère les offres d'emploi depuis la source.
     *
     * Les erreurs réseau ou de parsing doivent être absorbées en interne :
     * cette méthode ne doit jamais propager d'exception vers le pipeline.
     *
     * @return JobDTO[]
     */
    public function fetch(): array;
}
