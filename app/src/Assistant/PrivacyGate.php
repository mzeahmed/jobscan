<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Décide si des données du profil candidat peuvent être envoyées au moteur LLM actif.
 *
 * - Moteur local : autorisé.
 * - Moteur distant : refusé, sauf autorisation **explicite** (`ASSISTANT_ALLOW_REMOTE_LLM=true`).
 *
 * Même autorisé, un moteur distant ne reçoit ni nom, ni email, ni téléphone, ni prétentions salariales, ni fichier de CV.
 */
final readonly class PrivacyGate
{
    /** @param array<string, string> $baseUrls Adresse de chaque moteur configurable (`ollama`, `lmstudio`) */
    public function __construct(
        private string $provider,
        private array $baseUrls,
        private bool $allowRemote,
    ) {
    }

    public function isLocal(): bool
    {
        return LlmLocality::isLocal($this->provider, $this->baseUrls[strtolower($this->provider)] ?? null);
    }

    public function allowsProfileData(): bool
    {
        return $this->isLocal() || $this->allowRemote;
    }

    public function describe(): string
    {
        return sprintf('moteur %s (%s)', $this->provider, $this->isLocal() ? 'local' : 'distant');
    }
}
