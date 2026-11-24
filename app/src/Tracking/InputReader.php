<?php

declare(strict_types=1);

namespace App\Tracking;

/**
 * Lecture et validation des saisies utilisateur (commandes et interface web).
 */
final class InputReader
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

        // « Aujourd'hui » sans heure signifie maintenant : minuit précéderait un changement déjà consigné ce jour-là.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && $date->format('Y-m-d') === $now->format('Y-m-d')) {
            return $now;
        }

        return $date;
    }

    /**
     * Date quelconque (passée ou à venir), par exemple celle d'une relance.
     *
     * @throws \InvalidArgumentException
     */
    public static function anyDate(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('Date invalide : « %s ».', $value));
        }
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
