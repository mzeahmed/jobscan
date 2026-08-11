<?php

declare(strict_types=1);

namespace App\AI\Provider;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base commune aux moteurs LLM locaux exposant une API compatible OpenAI
 * (`/chat/completions`) : Ollama et LM Studio.
 *
 * Ces serveurs locaux ne valident pas la clé d'API — un jeton factice suffit.
 */
abstract class AbstractOpenAiCompatibleClient implements LLMClientInterface
{
    private const string DUMMY_API_KEY = 'not-required';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiBase,
        private readonly string $model,
    ) {
    }

    public function analyze(string $systemPrompt, string $userText): ?string
    {
        if (str_contains(strtolower($this->model), 'qwen3')) {
            $systemPrompt .= "\n\n/no_think";
        }

        try {
            $response = $this->httpClient
                ->request('POST', rtrim($this->apiBase, '/') . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . self::DUMMY_API_KEY,
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
            if (isset($data['error'])) {
                $error = \is_array($data['error']) ? ($data['error']['message'] ?? 'Erreur API inconnue.') : $data['error'];
                $this->logger->warning(static::class . '::analyze() a été refusé par le provider.', [
                    'error' => (string) $error,
                ]);

                return null;
            }

            if (!isset($data['choices'][0]['message']['content'])) {
                $this->logger->warning(static::class . '::analyze() a reçu une réponse sans contenu.', [
                    'status_code' => $response->getStatusCode(),
                ]);

                return null;
            }

            $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

            return $content !== '' ? $content : null;
        } catch (\Throwable $e) {
            $this->logger->warning(static::class . '::analyze() a échoué.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
