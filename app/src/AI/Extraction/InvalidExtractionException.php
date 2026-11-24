<?php

declare(strict_types=1);

namespace App\AI\Extraction;

/**
 * La réponse du LLM est inexploitable : le pipeline bascule sur l'extraction heuristique.
 */
final class InvalidExtractionException extends \RuntimeException
{
}
