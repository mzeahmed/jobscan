<?php

declare(strict_types=1);

namespace App\Health;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';

    public function symbol(): string
    {
        return match ($this) {
            self::Ok => '✔',
            self::Warning => '⚠',
            self::Critical => '✖',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    public static function worst(self ...$statuses): self
    {
        $worst = self::Ok;
        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
