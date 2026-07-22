<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Moteur d'analyse LLM LM Studio, via son endpoint compatible OpenAI `/chat/completions`.
 *
 * Configuré via `jobscan.llm.lmstudio.base_url` et `jobscan.llm.lmstudio.model`.
 */
final class LMStudioClient extends AbstractOpenAiCompatibleClient
{
}
