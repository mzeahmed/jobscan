<?php

declare(strict_types=1);

namespace App\Tracking;

/**
 * Opération refusée sur une candidature ou une offre (transition interdite, doublon, donnée invalide).
 */
final class InvalidApplicationException extends \DomainException
{
}
