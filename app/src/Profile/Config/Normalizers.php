<?php

declare(strict_types=1);

namespace App\Profile\Config;

/**
 * Normalisations communes aux schémas de profil, appliquées avant validation.
 */
final class Normalizers
{
    /** Une chaîne seule devient `['name' => chaîne]` : `- PHP` équivaut à `- name: PHP`. */
    public static function nameFromString(): \Closure
    {
        return static fn (mixed $value): mixed => is_string($value) ? ['name' => $value] : $value;
    }

    /**
     * YAML convertit une date non guillemetée (`2024-03-01`) en objet date : on la ramène au format voulu.
     */
    public static function date(string $format): \Closure
    {
        return static fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format($format) : $value;
    }

    /** Une valeur scalaire seule devient une liste d'un élément. */
    public static function toList(): \Closure
    {
        return static fn (mixed $value): mixed => is_scalar($value) ? [$value] : $value;
    }
}
