<?php

declare(strict_types=1);

namespace App\Command;

/**
 * Lecture des arguments communs aux commandes de candidature.
 */
final class ApplicationCommandSupport
{
    /** @throws \InvalidArgumentException */
    public static function date(mixed $value, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return $now;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Date invalide.');
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('Date invalide : « %s » (format attendu : 2026-12-01 ou 2026-12-01 14:30).', $value));
        }

        if ($date > $now->modify('+1 day')) {
            throw new \InvalidArgumentException('La date ne peut pas être dans le futur.');
        }

        return $date;
    }

    public static function id(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    public static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
