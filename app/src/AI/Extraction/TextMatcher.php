<?php

declare(strict_types=1);

namespace App\AI\Extraction;

/**
 * Vérifie qu'une information extraite par le LLM figure bel et bien dans l'annonce (« ancrage »).
 *
 * La comparaison ignore la casse, les accents et la ponctuation (« GitLab CI/CD » retrouve
 * « gitlab ci cd »). Les termes très courts (≤ 3 caractères : « Go », « C++ », « R ») ne sont
 * acceptés qu'en mot entier, pour qu'« Go » ne soit pas « retrouvé » dans « Google ».
 */
final readonly class TextMatcher
{
    private const array ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        "\u{2019}" => "'", "\u{00A0}" => ' ', "\u{202F}" => ' ',
    ];

    private string $folded;
    private string $compact;
    private string $spaced;

    public function __construct(string $source)
    {
        $this->folded = self::fold($source);
        $this->compact = self::compact($this->folded);
        $this->spaced = ' ' . self::spaced($this->folded) . ' ';
    }

    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text), self::ACCENTS);
    }

    public function contains(string $needle): bool
    {
        $folded = self::fold($needle);
        $compact = self::compact($folded);
        if ($compact === '') {
            return false;
        }

        if (strlen($compact) <= 3) {
            return str_contains($this->spaced, ' ' . self::spaced($folded) . ' ');
        }

        return str_contains($this->compact, $compact);
    }

    /** @param list<string> $needles */
    public function containsAny(array $needles): bool
    {
        return array_any($needles, $this->contains(...));
    }

    /**
     * Retrouve un montant tel qu'écrit dans l'annonce : `45000`, `45 000`, `45.000` ou `45k`.
     */
    public function containsAmount(int $amount): bool
    {
        // Retire les séparateurs de milliers (« 45 000 », « 45.000 ») sans toucher aux décimales.
        $digits = (string) preg_replace('/(?<=\d)[ .,](?=\d{3}(?!\d))/', '', $this->folded);

        // Ni précédé d'un chiffre ni suivi d'un chiffre ou d'une décimale : « 500.50 » n'est pas « 500 ».
        if (preg_match('/(?<![\d.,])' . $amount . '(?!\d|[.,]\d)/', $digits) === 1) {
            return true;
        }

        return $amount >= 1000 && $amount % 1000 === 0
            && preg_match('/(?<![\d.,])' . intdiv($amount, 1000) . '\s?k\b/', $digits) === 1;
    }

    private static function compact(string $folded): string
    {
        return (string) preg_replace('/[^a-z0-9+#]+/', '', $folded);
    }

    private static function spaced(string $folded): string
    {
        return trim((string) preg_replace('/[^a-z0-9+#]+/', ' ', $folded));
    }
}
