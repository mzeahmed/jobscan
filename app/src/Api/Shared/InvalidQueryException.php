<?php

declare(strict_types=1);

namespace App\Api\Shared;

/** Paramètres de requête invalides : un message en français par paramètre, renvoyé tel quel au client (422). */
final class InvalidQueryException extends \InvalidArgumentException
{
    /** @param array<string, string> $errors paramètre → message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Paramètres de requête invalides.');
    }
}
