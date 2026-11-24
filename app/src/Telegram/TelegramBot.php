<?php

declare(strict_types=1);

namespace App\Telegram;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appels de l'API Bot nécessaires à l'interaction (réception des mises à jour, réponses aux boutons).
 * L'envoi de messages reste celui de `TelegramNotifier`.
 *
 * Aucune méthode ne lève d'exception : un échec est journalisé (jeton masqué) et renvoie `null`/`false`.
 */
final readonly class TelegramBot
{
    private const string API_URL = 'https://api.telegram.org';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $botToken,
    ) {
    }

    /**
     * @param int $timeout Attente maximale (secondes) d'une mise à jour ; 0 : réponse immédiate
     *
     * @return list<array<string, mixed>>|null `null` si Telegram est injoignable ou refuse la requête
     */
    public function getUpdates(int $offset, int $timeout): ?array
    {
        $data = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], $timeout + 10);

        $result = $data['result'] ?? null;

        return is_array($result) ? array_values(array_filter($result, is_array(...))) : null;
    }

    public function answerCallback(string $callbackId, string $text): bool
    {
        return $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => mb_substr($text, 0, 200)], 10) !== null;
    }

    /** @param array<string, mixed> $markup */
    public function editKeyboard(int $chatId, int $messageId, array $markup): bool
    {
        return $this->call('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => $markup], 10) !== null;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>|null
     */
    private function call(string $method, array $parameters, int $timeout): ?array
    {
        try {
            $response = $this->httpClient->request('POST', sprintf('%s/bot%s/%s', self::API_URL, $this->botToken, $method), [
                'json' => $parameters,
                'timeout' => $timeout,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Appel Telegram en échec.', ['method' => $method, 'error' => str_replace($this->botToken, '[token]', $e->getMessage())]);

            return null;
        }

        if (($data['ok'] ?? false) !== true) {
            $this->logger->warning('Telegram a refusé l\'appel.', ['method' => $method, 'description' => is_string($data['description'] ?? null) ? $data['description'] : null]);

            return null;
        }

        return $data;
    }
}
