<?php

declare(strict_types=1);

namespace App\Health;

/** Seuils des contrôles de santé (`app.health.*`). */
final readonly class HealthThresholds
{
    public function __construct(
        /** Une source sans exécution depuis ce nombre d'heures est « en retard » (critique à 3×). */
        public int $providerStaleHours = 12,
        /** Nombre d'échecs consécutifs d'une source avant l'état critique. */
        public int $providerFailureStreak = 3,
        /** Analyses en attente à partir desquelles on s'inquiète. */
        public int $analysisPendingWarning = 50,
        /** Âge (heures) de la plus ancienne analyse en attente. */
        public int $analysisOldestHours = 24,
        /** Part d'analyses de repli (LLM indisponible) sur 7 jours, en pourcentage. */
        public int $fallbackSharePercent = 30,
        /** Notifications immédiates manquées depuis plus de ce nombre d'heures. */
        public int $notificationLagHours = 6,
        /** Rappels de relance non envoyés alors que la date est passée depuis plus de ce nombre d'heures. */
        public int $reminderLagHours = 6,
        public int $diskWarningPercent = 10,
        public int $diskCriticalPercent = 5,
        /** Priorité à partir de laquelle une offre doit être notifiée immédiatement. */
        public int $priorityThreshold = 80,
        /** Âge maximal (jours) d'une offre encore notifiable. */
        public int $maxAgeDays = 3,
    ) {
    }
}
