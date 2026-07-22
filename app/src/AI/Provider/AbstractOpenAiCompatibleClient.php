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
