<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Sélectionne le moteur d'analyse IA actif selon `AI_PROVIDER` (env).
 *
 * Ajouter un nouveau provider (Claude, OpenAI, ...) : créer une classe implémentant
 * `AIProviderInterface`, la déclarer dans `config/services.yaml`, l'injecter ici et
 * ajouter une entrée dans le `match()` ci-dessous.
 */
final class AIProviderFactory
{
    /**
     * @param  string  $providerName  Moteur IA à utiliser (env `AI_PROVIDER` — `ollama` ou `gemini`)
     */
    public function __construct(
        private readonly OpenAICompatibleProvider $openAICompatibleProvider,
        private readonly GeminiProvider $geminiProvider,
        private readonly string $providerName,
    ) {
    }

    public function create(): AIProviderInterface
    {
        return match (strtolower($this->providerName)) {
            'gemini' => $this->geminiProvider,
            'ollama', 'lmstudio' => $this->openAICompatibleProvider,
            default => throw new \InvalidArgumentException(\sprintf(
                'Provider IA inconnu : "%s". Valeurs supportées : "ollama", "gemini".',
                $this->providerName,
            )),
        };
    }
}
