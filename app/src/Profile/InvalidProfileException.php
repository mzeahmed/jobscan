<?php

declare(strict_types=1);

namespace App\Profile;

/**
 * Fichier de profil illisible ou invalide. Le message nomme le fichier et le champ fautif ;
 * il ne reproduit jamais le contenu du profil (données personnelles).
 */
final class InvalidProfileException extends \RuntimeException
{
}
