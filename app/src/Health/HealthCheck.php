<?php

declare(strict_types=1);

namespace App\Health;

/**
 * Résultat d'un contrôle : ce qui a été vérifié, son état, et ce qu'il faut faire s'il n'est pas bon.
 */
final readonly class HealthCheck
{
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $message,
    ) {
    }

    public static function ok(string $name, string $message): self
    {
        return new self($name, HealthStatus::Ok, $message);
    }

    public static function warning(string $name, string $message): self
    {
        return new self($name, HealthStatus::Warning, $message);
    }

    public static function critical(string $name, string $message): self
    {
        return new self($name, HealthStatus::Critical, $message);
    }
}
