<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Email d'alerte récupéré dans la boîte dédiée. Son contenu est **non fiable** : il ne
 * sert jamais qu'à extraire des liens d'offres et du texte, jamais à déclencher une action.
 */
final readonly class EmailMessage
{
    public function __construct(
        public string $folder,
        public ?string $uidValidity,
        public int $uid,
        public ?string $messageId,
        public string $fromAddress,
        public string $subject,
        public ?\DateTimeImmutable $receivedAt,
        public ?string $html,
        public ?string $text,
    ) {
    }

    public function dedupKey(): string
    {
        return self::keyFor($this->messageId, $this->folder, $this->uidValidity, $this->uid);
    }

    /**
     * Identité stable d'un email : son Message-ID s'il existe, sinon dossier + UIDVALIDITY + UID.
     * (UID seul ne suffit pas : ils repartent de zéro quand UIDVALIDITY change.)
     */
    public static function keyFor(?string $messageId, string $folder, ?string $uidValidity, int $uid): string
    {
        $messageId = $messageId === null ? '' : strtolower(trim($messageId, " \t\n\r\0\x0B<>"));

        return hash('sha256', $messageId !== '' ? 'mid:' . $messageId : sprintf('uid:%s|%s|%d', $folder, (string) $uidValidity, $uid));
    }

    /** Domaine de l'expéditeur, en minuscules. */
    public function senderDomain(): string
    {
        $at = strrpos($this->fromAddress, '@');

        return $at === false ? '' : strtolower(trim(substr($this->fromAddress, $at + 1), " >\t"));
    }
}
