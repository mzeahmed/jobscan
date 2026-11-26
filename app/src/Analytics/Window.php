<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * Fenêtre d'analyse : `30d`, `12w`, ou `all` (tout l'historique).
 */
final class Window
{
    /**
     * @return \DateTimeImmutable|null|false Date de début, `null` pour tout l'historique, `false` si l'expression est invalide
     * @throws \DateMalformedStringException
     */
    public static function since(string $expression, \DateTimeImmutable $now): \DateTimeImmutable | null | false
    {
        $expression = strtolower(trim($expression));
        if ($expression === 'all') {
            return null;
        }

        if (preg_match('/^(\d{1,4})([dw])$/', $expression, $matches) !== 1 || (int) $matches[1] < 1) {
            return false;
        }

        return $now->modify(sprintf('-%d %s', (int) $matches[1], $matches[2] === 'w' ? 'weeks' : 'days'));
    }
}
