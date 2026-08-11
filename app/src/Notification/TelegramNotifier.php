<?php

declare(strict_types=1);

namespace App\Notification;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoie des messages texte sur un canal Telegram via l'API Bot.
 *
 * Les messages sont envoyés en Markdown. Les erreurs réseau ou API sont
 * absorbées et journalisées — elles ne propagent jamais d'exception vers
 * l'appelant afin de ne pas interrompre le pipeline.
 */
final readonly class TelegramNotifier
{
    private const string API_URL = 'https://api.telegram.org';

    /**
     * @param string $botToken Token du bot Telegram (env `TELEGRAM_BOT_TOKEN`)
     * @param string $chatId Identifiant du canal ou du chat cible (env `TELEGRAM_CHAT_ID`)
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $botToken,
        private string $chatId,
        private int $maxAttempts = 3,
        private int $initialRetryDelayMs = 250,
    ) {
    }

    /**
     * Envoie un message vers le chat configuré.
     *
     * Toute exception levée par le client HTTP est capturée et journalisée
     * en niveau `error` sans être propagée.
     */
    public function send(string $message): bool
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = $this->httpClient->request('POST', sprintf(
                    '%s/bot%s/sendMessage',
                    self::API_URL,
                    $this->botToken
                ), [
                    'json' => [
                        'chat_id' => $this->chatId,
                        'text' => $message,
                        'parse_mode' => 'Markdown',
                    ],
                    'timeout' => 15,
                ]);

                $statusCode = $response->getStatusCode();
                $data = $response->toArray(false);
                if ($statusCode >= 200 && $statusCode < 300 && ($data['ok'] ?? false) === true) {
                    return true;
                }

                $this->logger->warning('Telegram a refusé la notification.', [
                    'attempt' => $attempt,
                    'status_code' => $statusCode,
                    'description' => $data['description'] ?? null,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Erreur Telegram.', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $this->maxAttempts && $this->initialRetryDelayMs > 0) {
                usleep($this->initialRetryDelayMs * (2 ** ($attempt - 1)) * 1000);
            }
        }

        $this->logger->error('Notification Telegram abandonnée après plusieurs tentatives.', [
            'attempts' => $this->maxAttempts,
        ]);

        return false;
    }
}
