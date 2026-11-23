<?php

declare(strict_types=1);

namespace App\Scoring;

/**
 * Recherche de mots-clés par mots entiers, insensible à la casse et aux accents.
 *
 * Évite les faux positifs d'un simple `str_contains` : « api » ne matche plus
 * « capital », « mission » ne matche plus « commission », « stage » ne matche
 * plus « backstage ».
 */
final class TextMatcher
{
    /**
     * Met le texte en minuscules et retire les accents (« télétravail » → « teletravail »).
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);

        if (\is_string($decomposed)) {
            $text = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
        }

        return $text;
    }

    /**
     * Indique si `$keyword` apparaît comme mot entier dans `$text`.
     *
     * Les deux arguments sont normalisés. Un mot est délimité par tout caractère
     * qui n'est ni une lettre ni un chiffre : `full-stack` et `c#` restent supportés.
     */
    public static function contains(string $text, string $keyword): bool
    {
        return self::containsNormalized(self::normalize($text), $keyword);
    }

    /**
     * Variante pour un texte déjà passé par `normalize()` (évite de le renormaliser par mot-clé).
     */
    public static function containsNormalized(string $normalizedText, string $keyword): bool
    {
        $keyword = self::normalize(trim($keyword));

        if ($keyword === '') {
            return false;
        }

        return 1 === preg_match(
            '/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '(?![\p{L}\p{N}])/u',
            $normalizedText,
        );
    }
}
