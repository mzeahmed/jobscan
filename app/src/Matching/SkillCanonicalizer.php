<?php

declare(strict_types=1);

namespace App\Matching;

use App\AI\Extraction\TextMatcher;

/**
 * Forme canonique d'un nom de compétence, pour comparer « Postgres » et « PostgreSQL », « Node.js » et « node ».
 *
 * La comparaison ignore la casse, les accents et la ponctuation, rapproche les alias configurés
 * (`app.profile.skill_aliases`) et **ignore les numéros de version** : « PHP 8 », « PHP8 » et « PHP »
 * sont la même compétence (une version précise n'est donc pas comparée).
 */
final readonly class SkillCanonicalizer
{
    /** @var array<string, string> clé d'alias → clé canonique */
    private array $aliases;

    /**
     * @param list<list<string>> $aliasGroups Groupes de noms équivalents ; le premier nom d'un groupe est sa forme canonique
     */
    public function __construct(array $aliasGroups = [])
    {
        $aliases = [];
        foreach ($aliasGroups as $group) {
            $keys = array_values(array_filter(array_map(self::base(...), $group), static fn (string $key): bool => $key !== ''));
            foreach ($keys as $key) {
                $aliases[$key] = $keys[0];
            }
        }

        $this->aliases = $aliases;
    }

    public function key(string $skill): string
    {
        $base = self::base($skill);

        return $this->aliases[$base] ?? $this->aliases[self::withoutVersion($base)] ?? self::withoutVersion($base);
    }

    private static function base(string $skill): string
    {
        return (string) preg_replace('/[^a-z0-9+#]+/', '', TextMatcher::fold($skill));
    }

    /** « php8 » → « php », « html5 » → « html » ; les noms courts (« es6 », « ec2 ») sont conservés. */
    private static function withoutVersion(string $key): string
    {
        return preg_match('/^(.{3,}?)\d+$/', $key, $matches) === 1 ? $matches[1] : $key;
    }
}
