<?php

declare(strict_types=1);

namespace App\AI\Provider;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Moteur d'analyse IA basé sur l'API Gemini de Google (Generative Language API).
 *
 * @see https://ai.google.dev/gemini-api/docs
 */
final class GeminiProvider implements AIProviderInterface
{
    private const string API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * @param  string  $apiKey  Clé d'API Gemini (env `GEMINI_API_KEY`)
     * @param  string  $model  Identifiant du modèle à utiliser (env `GEMINI_MODEL`)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function complete(string $systemPrompt, string $userText): ?string
    {
        try {
            $response = $this->httpClient
                ->request('POST', \sprintf('%s/%s:generateContent', self::API_BASE, $this->model), [
                    'headers' => [
                        'x-goog-api-key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'system_instruction' => [
                            'parts' => [['text' => $systemPrompt]],
                        ],
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $userText]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0,
                            'maxOutputTokens' => 1024,
                        ],
                    ],
                    'timeout' => 120,
                    'max_duration' => 120,
                ]);

            $data = $response->toArray(false);
            $content = trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));

            return $content !== '' ? $content : null;
        } catch (\Throwable $e) {
            $this->logger->warning('GeminiProvider::complete() a échoué.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
