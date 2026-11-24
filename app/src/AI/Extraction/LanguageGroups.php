<?php

declare(strict_types=1);

namespace App\AI\Extraction;

/**
 * Noms équivalents d'une même langue (anglais / english…), pour retrouver une langue dans une
 * annonce ou dans un profil quelle que soit la langue de rédaction.
 */
final class LanguageGroups
{
    private const array GROUPS = [
        ['anglais', 'english'], ['francais', 'french'], ['allemand', 'german', 'deutsch'], ['espagnol', 'spanish'],
        ['italien', 'italian'], ['portugais', 'portuguese'], ['neerlandais', 'dutch', 'flamand'], ['chinois', 'chinese', 'mandarin'],
        ['arabe', 'arabic'], ['russe', 'russian'], ['japonais', 'japanese'],
    ];

    /**
     * Tous les noms de la langue (sans accent, en minuscules), ou le nom seul s'il n'appartient à aucun groupe.
     *
     * @return list<string>
     */
    public static function names(string $name): array
    {
        $folded = TextMatcher::fold(trim($name));
        foreach (self::GROUPS as $group) {
            if (in_array($folded, $group, true)) {
                return $group;
            }
        }

        return [trim($name)];
    }

    /** Clé de comparaison stable : deux noms de la même langue ont la même clé. */
    public static function key(string $name): string
    {
        $names = self::names($name);

        return TextMatcher::fold($names[0]);
    }
}
