<?php

declare(strict_types=1);

namespace App\Email;

use Psr\Log\LoggerInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageInterface;

/**
 * Lecture d'une boîte IMAP via `directorytree/imapengine` (PHP pur, sans `ext-imap`).
 *
 * Authentification : mot de passe (de préférence un mot de passe d'application) ou jeton
 * d'accès OAuth2 (`authentication: oauth`, le « mot de passe » étant alors le jeton ; son
 * renouvellement n'est pas géré ici). Les messages sont lus en mode « peek » : la boîte n'est
 * jamais modifiée. Ni le mot de passe ni le jeton ne sont journalisés.
 */
final readonly class ImapEmailClient implements EmailClientInterface
{
    /** @param null|\Closure(): MailboxInterface $mailboxFactory Fabrique de boîte, injectable pour les tests */
    public function __construct(
        private LoggerInterface $logger,
        private string $host = '',
        private int $port = 993,
        private string $encryption = 'ssl',
        private string $username = '',
        #[\SensitiveParameter]
        private string $password = '',
        private string $authentication = 'plain',
        private string $folder = 'INBOX',
        private bool $validateCertificate = true,
        private ?\Closure $mailboxFactory = null,
    ) {
    }

    public function isHealthy(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $mailbox = null;
        try {
            $mailbox = $this->mailbox();
            $mailbox->folders()->findOrFail($this->folder);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Boîte email indisponible.', ['error' => $e->getMessage()]);

            return false;
        } finally {
            $this->disconnect($mailbox);
        }
    }

    public function fetchSince(\DateTimeImmutable $since, int $limit, \Closure $isProcessed): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Boîte email non configurée (hôte, utilisateur ou mot de passe manquant).');
        }

        $mailbox = null;
        try {
            $mailbox = $this->mailbox();
            $folder = $mailbox->folders()->findOrFail($this->folder);
            $uidValidity = $this->uidValidity($folder);

            // En-têtes seuls, du plus ancien au plus récent. La bibliothèque lit en mode « peek » par
            // défaut : la boîte n'est pas modifiée (les messages restent non lus).
            $query = $folder->messages()->withHeaders()->withoutBody()->oldest();
            $query->since($since);
            $headers = $query->get();

            $messages = [];
            foreach ($headers as $header) {
                if (count($messages) >= max(1, $limit)) {
                    break;
                }

                $key = EmailMessage::keyFor($header->messageId(), $this->folder, $uidValidity, $header->uid());
                if ($isProcessed($key)) {
                    continue;
                }

                try {
                    $messages[] = $this->download($folder, $header, $uidValidity);
                } catch (\Throwable $e) {
                    // Non marqué : l'email sera retenté à la prochaine exécution.
                    $this->logger->warning('Email illisible, retenté plus tard.', ['uid' => $header->uid(), 'error' => $e->getMessage()]);
                }
            }

            return $messages;
        } finally {
            $this->disconnect($mailbox);
        }
    }

    private function download(FolderInterface $folder, MessageInterface $header, ?string $uidValidity): EmailMessage
    {
        $full = $folder->messages()->withHeaders()->withBody()->find($header->uid())
            ?? throw new \RuntimeException(sprintf('Message %d introuvable.', $header->uid()));
        $date = $header->date();

        return new EmailMessage(
            folder: $this->folder,
            uidValidity: $uidValidity,
            uid: $header->uid(),
            messageId: $header->messageId(),
            fromAddress: $header->from()?->email() ?? '',
            subject: (string) $header->subject(),
            receivedAt: $date === null ? null : \DateTimeImmutable::createFromInterface($date),
            html: $full->html(),
            text: $full->text(),
        );
    }

    private function uidValidity(FolderInterface $folder): ?string
    {
        try {
            $value = $folder->status()['UIDVALIDITY'] ?? null;

            return is_scalar($value) ? (string) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isConfigured(): bool
    {
        return trim($this->host) !== '' && trim($this->username) !== '' && $this->password !== '';
    }

    private function mailbox(): MailboxInterface
    {
        if ($this->mailboxFactory !== null) {
            return ($this->mailboxFactory)();
        }

        return Mailbox::make([
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'password' => $this->password,
            'authentication' => $this->authentication === 'oauth' ? 'oauth' : 'plain',
            'validate_cert' => $this->validateCertificate,
            'timeout' => 30,
        ]);
    }

    private function disconnect(?MailboxInterface $mailbox): void
    {
        try {
            $mailbox?->disconnect();
        } catch (\Throwable) {
            // Rien à faire : la connexion est abandonnée.
        }
    }
}
