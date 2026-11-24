<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Échappement pour le mode HTML de Telegram.
 *
 * Telegram n'accepte que les entités nommées `&lt;` `&gt;` `&amp;` `&quot;` et les entités numériques :
 * `ENT_HTML5` produirait `&apos;` pour une apostrophe, que Telegram n'interprète pas, alors que les titres
 * français en contiennent souvent. `ENT_HTML401` produit `&#039;`, accepté.
 */
final class TelegramHtml
{
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }

    /** Lien cliquable, ou texte seul si l'adresse n'est pas une URL http(s) valide. */
    public static function link(string $url, string $label): string
    {
        $valid = filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);

        return $valid
            ? sprintf('<a href="%s">%s</a>', self::escape($url), self::escape($label))
            : self::escape($label);
    }
}
