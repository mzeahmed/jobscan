<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * Boutons d'un rappel de relance. Les identifiants sont ceux des **candidatures** (et non des offres, voir `JobKeyboard`).
 */
final class ReminderKeyboard
{
    public const string DONE = 'r';
    public const string SHORT = 'p';
    public const string LONG = 'q';

    public const int SHORT_DAYS = 3;
    public const int LONG_DAYS = 7;

    /** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
    public static function forApplication(int $applicationId): array
    {
        return ['inline_keyboard' => [[
            ['text' => '✅ Relancé', 'callback_data' => self::DONE . $applicationId],
            ['text' => sprintf('⏰ +%d j', self::SHORT_DAYS), 'callback_data' => self::SHORT . $applicationId],
            ['text' => sprintf('⏰ +%d j', self::LONG_DAYS), 'callback_data' => self::LONG . $applicationId],
        ]]];
    }

    /** @return array{0: string, 1: int}|null */
    public static function parse(string $data): ?array
    {
        if (preg_match('/^([rpq])(\d{1,12})$/', $data, $matches) !== 1) {
            return null;
        }

        return [$matches[1], (int) $matches[2]];
    }
}
