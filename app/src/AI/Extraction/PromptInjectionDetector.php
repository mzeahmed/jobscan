<?php

declare(strict_types=1);

namespace App\AI\Extraction;

/**
 * Repère, dans un texte d'annonce, les consignes adressées à un modèle d'IA (« ignore les instructions
 * précédentes »…) et les neutralise. La détection est volontairement restreinte, pour éviter les faux positifs.
 *
 * Les défenses de fond sont `PromptBuilder` (annonce traitée comme donnée) et `RequirementsValidator`
 * (réponse validée et ancrée). Ce détecteur les complète : un petit modèle local peut obéir à une consigne
 * malgré les règles de sécurité, et l'ancrage ne protège pas contre une consigne qui contient elle-même
 * la charge utile. Le passage suspect est donc **retiré** du texte avant l'envoi au modèle et avant l'ancrage.
 * Une consigne reformulée peut échapper à ces motifs : ce n'est qu'une couche parmi d'autres.
 */
final readonly class PromptInjectionDetector
{
    private const array PATTERNS = [
        '/\b(ignore|ignorez|ignorer)\s+(toutes?\s+)?(les\s+|tes\s+|vos\s+)?(instructions?|consignes?|regles?)/',
        '/\b(ignore|disregard|forget)\s+(all\s+|any\s+)?(the\s+|your\s+)?(previous|prior|above|earlier|system)\s+(instructions?|prompts?|rules?)/',
        '/\b(oublie|oubliez|oublier)\s+(toutes?\s+)?(les\s+|tes\s+|vos\s+)?(instructions?|consignes?)/',
        '/\b(prompt\s+systeme|system\s+prompt|instructions?\s+systeme)\b/',
        '/\b(you\s+are\s+now|tu\s+es\s+maintenant|vous\s+etes\s+maintenant)\b/',
    ];

    /** Au plus ce nombre de caractères avant le motif sont écartés avec sa phrase. */
    private const int MAX_SENTENCE_LOOKBACK = 300;

    public function isSuspicious(string $text): bool
    {
        return $this->injectionOffset($text) !== null;
    }

    /**
     * Retire du texte la phrase contenant la première consigne détectée, et tout ce qui la suit : le reste
     * d'une annonce qui s'adresse à un modèle n'est plus digne de confiance.
     *
     * @return array{0: string, 1: bool} Texte conservé, et vrai si un passage a été retiré
     */
    public function neutralize(string $text): array
    {
        $offset = $this->injectionOffset($text);
        if ($offset === null) {
            return [$text, false];
        }

        $characters = mb_str_split($text);
        $start = $offset;
        $limit = max(0, $offset - self::MAX_SENTENCE_LOOKBACK);
        while ($start > $limit && !in_array($characters[$start - 1], ['.', '!', '?', "\n"], true)) {
            --$start;
        }

        return [rtrim(implode('', array_slice($characters, 0, $start))), true];
    }

    /** Index (en caractères) du début de la première consigne détectée. */
    private function injectionOffset(string $text): ?int
    {
        // Le texte replié (sans accents ni casse) peut changer de longueur : on garde la correspondance
        // octet replié → caractère d'origine pour retrouver la position dans le texte d'origine.
        $folded = '';
        $map = [];
        foreach (mb_str_split($text) as $index => $character) {
            $piece = TextMatcher::fold($character);
            for ($byte = 0, $length = strlen($piece); $byte < $length; ++$byte) {
                $map[strlen($folded) + $byte] = $index;
            }

            $folded .= $piece;
        }

        $first = null;
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $folded, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $position = $map[$matches[0][1]] ?? null;
                if ($position !== null && ($first === null || $position < $first)) {
                    $first = $position;
                }
            }
        }

        return $first;
    }
}
