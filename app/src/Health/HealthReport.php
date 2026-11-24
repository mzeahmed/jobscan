<?php

declare(strict_types=1);

namespace App\Health;

final readonly class HealthReport
{
    /**
     * @param list<HealthCheck> $checks
     */
    public function __construct(
        public \DateTimeImmutable $generatedAt,
        public array $checks,
    ) {
    }

    public function status(): HealthStatus
    {
        return HealthStatus::worst(...array_map(static fn (HealthCheck $check): HealthStatus => $check->status, $this->checks));
    }

    /**
     * @return list<HealthCheck>
     */
    public function problems(): array
    {
        return array_values(array_filter($this->checks, static fn (HealthCheck $check): bool => $check->status !== HealthStatus::Ok));
    }

    /**
     * Empreinte des problèmes en cours : change quand un problème apparaît, disparaît ou change de gravité, pas quand son texte varie.
     */
    public function signature(): string
    {
        $problems = array_map(static fn (HealthCheck $check): string => $check->name . '=' . $check->status->value, $this->problems());
        sort($problems);

        return implode('|', $problems);
    }
}
