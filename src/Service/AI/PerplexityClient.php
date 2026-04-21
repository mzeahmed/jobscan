<?php

declare(strict_types=1);

namespace App\Service\AI;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PerplexityClient
{
    private const API_URL = 'https://api.perplexity.ai/chat/completions';
    private const MODEL = 'llama-3.1-sonar-small-128k-online';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey = '',
    ) {
    }

    /**
     * Analyse le texte d'une offre et retourne les métadonnées extraites.
     *
     * Retour attendu :
     * {
     *   "stack": ["php", "symfony", "wordpress"],
     *   "freelance": true,
     *   "remote": true,
     *   "budget": "500€/j",
     *   "recent": true
     * }
     */
    public function analyze(string $text): array
    {
        if ('' === $this->apiKey) {
            return $this->simulateAnalysis($text);
        }

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => self::MODEL,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "Analyse cette offre d'emploi. Réponds uniquement en JSON valide avec les champs: stack (array de strings), freelance (bool), remote (bool), budget (string), recent (bool).",
                        ],
                        ['role' => 'user', 'content' => $text],
                    ],
                ],
            ]);

            $data = $response->toArray();
            $content = $data['choices'][0]['message']['content'] ?? '{}';

            // Extraire le JSON même si l'IA ajoute du texte autour
            if (preg_match('/\{.*\}/s', $content, $m)) {
                $parsed = json_decode($m[0], true);
                if (\is_array($parsed)) {
                    return $parsed;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('PerplexityClient::analyze() a échoué, fallback simulation.', ['error' => $e->getMessage()]);
        }

        return $this->simulateAnalysis($text);
    }

    /**
     * Recherche des offres via Perplexity.
     *
     * @return array<array{title: string, url: string, description: string}>
     */
    public function searchJobs(): array
    {
        if ('' === $this->apiKey) {
            return $this->simulateJobSearch();
        }

        try {
            $response = $this->httpClient
                ->request('POST', self::API_URL, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => self::MODEL,
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => "Liste les 5 dernières offres freelance PHP Symfony remote publiées aujourd'hui. Retourne uniquement un tableau JSON avec les champs: title, url, description.",
                            ],
                        ],
                    ],
                ]);

            $data = $response->toArray();
            $content = $data['choices'][0]['message']['content'] ?? '[]';

            if (preg_match('/\[.*\]/s', $content, $m)) {
                $parsed = json_decode($m[0], true);

                if (\is_array($parsed)) {
                    return $parsed;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('PerplexityClient::searchJobs() a échoué, fallback simulation.', ['error' => $e->getMessage()]);
        }

        return $this->simulateJobSearch();
    }

    private function simulateAnalysis(string $text): array
    {
        $lower = strtolower($text);

        return [
            'stack' => $this->extractStack($lower),
            'freelance' => str_contains($lower, 'freelance') || str_contains($lower, 'mission'),
            'remote' => str_contains($lower, 'remote') || str_contains($lower, 'télétravail') || str_contains($lower, 'teletravail'),
            'budget' => $this->extractBudget($lower),
            'recent' => true,
        ];
    }

    private function extractStack(string $text): array
    {
        $known = ['php', 'symfony', 'wordpres', 'mysql', 'postgresql', 'redis', 'docker', 'react', 'vue', 'api', 'rabbitmq'];

        return array_values(array_filter($known, fn (string $tech) => str_contains($text, $tech)));
    }

    private function extractBudget(string $text): string
    {
        $text = strtolower($text);

        // -------------------------
        // Freelance (TJM)
        // ex: 500€/j, 450 / jour
        // -------------------------
        if (preg_match('/(\d{3,4})\s*€?\s*\/?\s*j(our)?/i', $text, $m)) {
            return $m[1] . '€/j';
        }

        // -------------------------
        // CDI (salaire annuel)
        // ex: 42k€, 45k, 40-50k
        // -------------------------

        // plage : 40-50k
        if (preg_match('/(\d{2,3})\s*[-–]\s*(\d{2,3})\s*k/i', $text, $m)) {
            return $m[1] . '-' . $m[2] . 'k€/an';
        }

        // simple : 42k€
        if (preg_match('/(\d{2,3})\s*k\s*€?/i', $text, $m)) {
            return $m[1] . 'k€/an';
        }

        return 'non précisé';
    }

    private function simulateJobSearch(): array
    {
        return [
            [
                'title' => 'Freelance Symfony Senior — Full Remote',
                'url' => 'https://perplexity.ai/search/job-symfony-' . uniqid(),
                'description' => 'Mission freelance PHP/Symfony 6+ mois, full remote. TJM 550€. Stack PHP 8, Symfony 7, PostgreSQL, RabbitMQ.',
            ],
        ];
    }
}
