<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Brouillon de message de candidature. Jamais envoyé : l'utilisateur le relit, l'adapte et l'envoie lui-même.
 */
final readonly class Draft
{
    public const string SOURCE_TEMPLATE = 'template';
    public const string SOURCE_LLM = 'llm';

    /**
     * @param list<string> $checks Points à vérifier avant l'envoi
     */
    public function __construct(
        public string $subject,
        public string $body,
        public string $source,
        public array $checks,
    ) {
    }

    public function text(): string
    {
        return "Objet : {$this->subject}\n\n{$this->body}";
    }
}
