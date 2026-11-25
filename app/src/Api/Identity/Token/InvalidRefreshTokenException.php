<?php

declare(strict_types=1);

namespace App\Api\Identity\Token;

/** Jeton de rafraîchissement inconnu, expiré, révoqué ou déjà utilisé. Le détail n'est jamais exposé au client. */
final class InvalidRefreshTokenException extends \RuntimeException
{
}
