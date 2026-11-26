<?php

declare(strict_types=1);

namespace App\Notification;

use App\AI\Extraction\ExtractionSource;

/**
 * Règles de notification : à partir de quel score de priorité une offre est prioritaire, intéressante
 * ou secondaire, et ce qu'on en fait.
 *
 * - **Prioritaire** : toujours notifiée immédiatement (et bruyamment).
 * - **Intéressante** : `interesting_mode` — immédiate (silencieuse), récapitulatif ou aucune.
 * - **Secondaire** : `secondary_mode` — récapitulatif ou aucune.
 *
 * Seules des offres **compatibles** (verdict `eligible`) sont notifiées : une offre éliminée, sous le seuil ou
 * aux données insuffisantes ne l'est jamais, quelle que soit sa fraîcheur. Une extraction heuristique, qui ne
 * sait pas ce que l'annonce exige, ne peut pas déclencher une notification prioritaire.
 */
final readonly class NotificationPolicy
{
    private NotificationMode $interestingMode;
    private NotificationMode $secondaryMode;

    /**
     * @param int $priorityThreshold Priorité minimale d'une opportunité prioritaire
     * @param int $interestingThreshold Priorité minimale d'une offre intéressante
     * @param int $secondaryThreshold Priorité minimale d'une offre secondaire
     * @param string $interestingMode `immediate`, `digest` ou `off`
     * @param string $secondaryMode `digest` ou `off`
     * @param int $digestMaxItems Offres reprises au maximum dans un récapitulatif
     * @param int $maxAgeDays Une offre détectée depuis plus longtemps n'est plus notifiée (récapitulatif, rattrapage)
     */
    public function __construct(
        private int $priorityThreshold = 80,
        private int $interestingThreshold = 60,
        private int $secondaryThreshold = 40,
        string $interestingMode = 'immediate',
        string $secondaryMode = 'digest',
        private int $digestMaxItems = 15,
        private int $maxAgeDays = 3,
    ) {
        foreach ([$priorityThreshold, $interestingThreshold, $secondaryThreshold] as $threshold) {
            if ($threshold < 0 || $threshold > 100) {
                throw new \InvalidArgumentException('Les seuils de notification doivent être compris entre 0 et 100.');
            }
        }

        if (!($priorityThreshold >= $interestingThreshold && $interestingThreshold >= $secondaryThreshold)) {
            throw new \InvalidArgumentException('Les seuils de notification doivent décroître : prioritaire ≥ intéressante ≥ secondaire.');
        }

        if ($digestMaxItems < 1 || $maxAgeDays < 1) {
            throw new \InvalidArgumentException('Le nombre maximal d\'offres du récapitulatif et l\'ancienneté maximale doivent être positifs.');
        }

        $this->interestingMode = NotificationMode::tryFrom($interestingMode)
            ?? throw new \InvalidArgumentException(sprintf('Mode de notification inconnu : « %s » (immediate, digest ou off).', $interestingMode));

        $secondary = NotificationMode::tryFrom($secondaryMode);
        if ($secondary === null || $secondary === NotificationMode::Immediate) {
            throw new \InvalidArgumentException(sprintf('Mode de notification des offres secondaires invalide : « %s » (digest ou off).', $secondaryMode));
        }

        $this->secondaryMode = $secondary;
    }

    public function levelFor(int $priorityScore, ExtractionSource $source = ExtractionSource::Llm): ?NotificationLevel
    {
        $level = match (true) {
            $priorityScore >= $this->priorityThreshold => NotificationLevel::Priority,
            $priorityScore >= $this->interestingThreshold => NotificationLevel::Interesting,
            $priorityScore >= $this->secondaryThreshold => NotificationLevel::Secondary,
            default => null,
        };

        // Une extraction heuristique ne peut pas conclure à une opportunité prioritaire.
        return $level === NotificationLevel::Priority && $source === ExtractionSource::Heuristic ? NotificationLevel::Interesting : $level;
    }

    public function mode(NotificationLevel $level): NotificationMode
    {
        return match ($level) {
            NotificationLevel::Priority => NotificationMode::Immediate,
            NotificationLevel::Interesting => $this->interestingMode,
            NotificationLevel::Secondary => $this->secondaryMode,
        };
    }

    /** Seule l'opportunité prioritaire déclenche une notification sonore. */
    public function isSilent(NotificationLevel $level): bool
    {
        return $level !== NotificationLevel::Priority;
    }

    /** Priorité en dessous de laquelle aucune offre n'est jamais notifiée, tous niveaux confondus. */
    public function minimumPriority(): int
    {
        $thresholds = [NotificationLevel::Priority->value => $this->priorityThreshold];
        if ($this->interestingMode !== NotificationMode::Off) {
            $thresholds[NotificationLevel::Interesting->value] = $this->interestingThreshold;
        }

        if ($this->secondaryMode !== NotificationMode::Off) {
            $thresholds[NotificationLevel::Secondary->value] = $this->secondaryThreshold;
        }

        return min($thresholds);
    }

    public function digestMaxItems(): int
    {
        return $this->digestMaxItems;
    }

    /** Date de détection la plus ancienne qu'une offre peut avoir pour encore être notifiée. */
    public function oldestNotifiable(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->maxAgeDays));
    }
}
