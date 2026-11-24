<?php

declare(strict_types=1);

namespace App\Email;

interface EmailClientInterface
{
    /**
     * Sonde de disponibilité : connexion, authentification et existence du dossier.
     * Ne propage jamais d'exception.
     */
    public function isHealthy(): bool;

    /**
     * Retourne, du plus ancien au plus récent, jusqu'à `$limit` emails reçus depuis `$since`
     * que `$isProcessed` ne connaît pas encore. Ne modifie jamais l'état de la boîte
     * (pas de marquage lu/non lu) : le suivi des emails traités est tenu par Jobscan.
     *
     * @param \Closure(string): bool $isProcessed Reçoit la clé de déduplication de l'email
     * @return list<EmailMessage>
     */
    public function fetchSince(\DateTimeImmutable $since, int $limit, \Closure $isProcessed): array;
}
