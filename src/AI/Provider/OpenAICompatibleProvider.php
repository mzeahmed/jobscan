<?php

declare(strict_types=1);

namespace App\AI\Provider;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Moteur d'analyse IA pour tout provider local compatible OpenAI (Ollama par défaut,
 * LM Studio en legacy) via l'endpoint `/chat/completions`.
 */
final class OpenAICompatibleProvider implements AIProviderInterface
{
    /**
     * @param  string  $apiBase  URL de base de l'API compatible OpenAI (env `AI_API_BASE`)
     * @param  string  $apiKey  Clé d'API (env `AI_API_KEY` — `ollama` pour Ollama, `lmstudio` pour LM Studio)
     * @param  string  $model  Identifiant du modèle à utiliser (env `AI_MODEL`)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiBase,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function complete(string $systemPrompt, string $userText): ?string
    {
        try {
            $response = $this->httpClient
                ->request('POST', rtrim($this->apiBase, '/') . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $this->model,
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $userText],
                        ],
                        'temperature' => 0,
                        'max_tokens' => 1024,
                        'think' => false,
                    ],
                    'timeout' => 120,
                    'max_duration' => 120,
                ]);

            $data = $response->toArray(false);
            $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

            return $content !== '' ? $content : null;
        } catch (\Throwable $e) {
            $this->logger->warning('OpenAICompatibleProvider::complete() a échoué.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
