<?php

declare(strict_types=1);

namespace App\Api\Shared;

/** Entrée invalide (paramètres de requête ou corps JSON) : un message en français par champ, renvoyé tel quel au client (422). */
final class InvalidInputException extends \InvalidArgumentException
{
    /** @param array<string, string> $errors champ → message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Entrée invalide.');
    }
}
