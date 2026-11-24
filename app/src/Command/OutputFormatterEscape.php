<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Empêche qu'un texte venu d'une annonce (ex. `<error>`) soit interprété comme une balise de mise en forme du terminal.
 */
final class OutputFormatterEscape
{
    public static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
