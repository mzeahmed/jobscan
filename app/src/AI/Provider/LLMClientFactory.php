<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Sélectionne le moteur LLM actif selon `jobscan.llm.provider` (config).
 *
 * Ajouter un nouveau provider (Claude, OpenAI, ...) : créer une classe implémentant
 * `LLMClientInterface`, la déclarer dans `config/services.yaml`, l'injecter ici et
 * ajouter une entrée dans le `match()` ci-dessous.
 */
final class LLMClientFactory
{
    /**
     * @param  string  $providerName  Moteur LLM à utiliser (config `jobscan.llm.provider` — `ollama`, `lmstudio` ou `gemini`)
     */
    public function __construct(
        private readonly OllamaClient $ollamaClient,
        private readonly LMStudioClient $lmStudioClient,
        private readonly GeminiClient $geminiClient,
        private readonly string $providerName,
    ) {
    }

    public function create(): LLMClientInterface
    {
        return match (strtolower($this->providerName)) {
            'ollama' => $this->ollamaClient,
            'lmstudio' => $this->lmStudioClient,
            'gemini' => $this->geminiClient,
            default => throw new \InvalidArgumentException(\sprintf(
                'Provider LLM inconnu : "%s". Valeurs supportées : "ollama", "lmstudio", "gemini".',
                $this->providerName,
            )),
        };
    }
}
