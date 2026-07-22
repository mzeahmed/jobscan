<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Contrat commun à tous les moteurs d'analyse LLM (Ollama, LM Studio, Gemini, etc.).
 *
 * Une implémentation ne fait qu'exécuter l'appel réseau et retourner le contenu texte
 * brut de la réponse. Le parsing JSON, la normalisation et le fallback heuristique
 * restent centralisés dans `AIClient`.
 */
interface LLMClientInterface
{
    /**
     * Envoie le prompt système et le texte utilisateur au moteur LLM.
     *
     * Retourne `null` si l'appel échoue ou si la réponse est vide — `AIClient`
     * bascule alors automatiquement sur son fallback heuristique.
     */
    public function analyze(string $systemPrompt, string $userText): ?string;
}
