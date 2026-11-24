<?php

declare(strict_types=1);

namespace App\Reminder;

/**
 * @param int $due Relances dues et pas encore rappelées (dans la limite demandée)
 * @param int $sent Rappels effectivement envoyés
 * @param list<string> $previews Messages (aperçu en mode simulation)
 */
final readonly class ReminderResult
{
    /** @param list<string> $previews */
    public function __construct(
        public int $due,
        public int $sent,
        public array $previews = [],
    ) {
    }

    public function failed(): int
    {
        return $this->due - $this->sent;
    }
}
