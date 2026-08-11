<?php

declare(strict_types=1);

namespace App\Notification;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoie des messages texte sur un canal Telegram via l'API Bot.
 *
 * Les messages sont envoyés en HTML. Les erreurs réseau ou API sont
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
        $attempts = 0;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $attempts = $attempt;
            $retryDelayMs = $this->initialRetryDelayMs * (2 ** ($attempt - 1));

            try {
                $response = $this->httpClient->request('POST', sprintf(
                    '%s/bot%s/sendMessage',
                    self::API_URL,
                    $this->botToken
                ), [
                    'json' => [
                        'chat_id' => $this->chatId,
                        'text' => $message,
                        'parse_mode' => 'HTML',
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

                if (!$this->isRetryableStatus($statusCode)) {
                    break;
                }

                $retryAfter = $data['parameters']['retry_after'] ?? null;
                if (is_numeric($retryAfter)) {
                    $retryDelayMs = max(0, (int) $retryAfter * 1000);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Erreur Telegram.', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($attempt < $this->maxAttempts && $retryDelayMs > 0) {
                usleep($retryDelayMs * 1000);
            }
        }

        $this->logger->error('Notification Telegram abandonnée après plusieurs tentatives.', [
            'attempts' => $attempts,
        ]);

        return false;
    }

    private function isRetryableStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }
}
