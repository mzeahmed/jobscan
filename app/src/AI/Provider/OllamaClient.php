<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Moteur d'analyse LLM Ollama, via son endpoint compatible OpenAI `/chat/completions`.
 *
 * Configuré via `jobscan.llm.ollama.base_url` et `jobscan.llm.ollama.model`.
 */
final class OllamaClient extends AbstractOpenAiCompatibleClient
{
}
