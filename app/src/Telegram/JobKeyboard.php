<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Entity\Job;
use App\Tracking\JobUserState;

/**
 * Boutons d'un message d'offre. Chaque bouton porte `action:identifiant` (64 octets au plus pour Telegram) ;
 * l'action n'est exécutée que si la mise à jour vient du chat autorisé (voir `UpdateAuthorizer`).
 */
final class JobKeyboard
{
    public const string TRACK = 't';
    public const string SAVE = 's';
    public const string IGNORE = 'i';
    public const string APPLIED = 'a';
    public const string RESET = 'u';

    /**
     * @param bool $draft Une candidature existe, encore en brouillon : proposer « J'ai postulé »
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function forJob(Job $job, bool $tracked = false, bool $draft = false): array
    {
        $id = (int) $job->getId();
        $state = $job->getUserState();

        if ($tracked) {
            // Sans bouton une fois la candidature passée à « postulé » : la suite se gère dans l'interface web.
            return ['inline_keyboard' => $draft ? [[self::button('✅ J\'ai postulé', self::APPLIED, $id)]] : []];
        }

        if ($state !== null) {
            return ['inline_keyboard' => [[self::button('↩️ Annuler (' . mb_strtolower($state->label()) . ')', self::RESET, $id)]]];
        }

        return ['inline_keyboard' => [[
            self::button('📝 Suivre', self::TRACK, $id),
            self::button('⭐ Garder', self::SAVE, $id),
            self::button('🚫 Ignorer', self::IGNORE, $id),
        ]]];
    }

    /** @return array{0: string, 1: int}|null Action et identifiant d'offre, ou `null` si la donnée n'est pas de la forme attendue */
    public static function parse(string $data): ?array
    {
        if (preg_match('/^([tsiau])(\d{1,12})$/', $data, $matches) !== 1) {
            return null;
        }

        return [$matches[1], (int) $matches[2]];
    }

    public static function stateFor(string $action): ?JobUserState
    {
        return match ($action) {
            self::SAVE => JobUserState::Saved,
            self::IGNORE => JobUserState::Ignored,
            default => null,
        };
    }

    /** @return array{text: string, callback_data: string} */
    private static function button(string $text, string $action, int $id): array
    {
        return ['text' => $text, 'callback_data' => $action . $id];
    }
}
