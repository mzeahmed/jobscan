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
    ) {
    }

    /**
     * Envoie un message vers le chat configuré.
     *
     * Toute exception levée par le client HTTP est capturée et journalisée
     * en niveau `error` sans être propagée.
     */
    public function send(string $message): void
    {
        try {
            $this->httpClient->request('POST', sprintf(
                '%s/bot%s/sendMessage',
                self::API_URL,
                $this->botToken
            ), [
                'json' => [
                    'chat_id' => $this->chatId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur Telegram', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
