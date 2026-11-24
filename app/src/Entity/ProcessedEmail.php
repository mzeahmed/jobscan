<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Email\ProcessedEmailStatus;
use App\Repository\ProcessedEmailRepository;

/**
 * Suivi persistant des emails d'alerte traités (idempotence). Ne conserve ni le corps ni les
 * pièces jointes : seulement de quoi identifier l'email et expliquer ce qui en a été fait.
 */
#[ORM\Entity(repositoryClass: ProcessedEmailRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_processed_email_key', columns: ['dedup_key'])]
#[ORM\Index(name: 'idx_processed_email_processed_at', columns: ['processed_at'])]
class ProcessedEmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $dedupKey;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $messageId;

    #[ORM\Column(length: 255)]
    private string $folder;

    #[ORM\Column]
    private int $uid;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $uidValidity;

    #[ORM\Column(length: 255)]
    private string $sender;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $receivedAt;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error;

    public function __construct(
        \App\Email\EmailMessage $email,
        ProcessedEmailStatus $status,
        #[ORM\Column(length: 50, nullable: true)]
        private ?string $platform,
        #[ORM\Column]
        private int $offersCount,
        ?string $error,
        #[ORM\Column]
        private \DateTimeImmutable $processedAt,
    ) {
        $this->dedupKey = $email->dedupKey();
        $this->messageId = $email->messageId === null ? null : mb_substr($email->messageId, 0, 255);
        $this->folder = mb_substr($email->folder, 0, 255);
        $this->uid = $email->uid;
        $this->uidValidity = $email->uidValidity === null ? null : mb_substr($email->uidValidity, 0, 20);
        $this->sender = mb_substr($email->fromAddress, 0, 255);
        $this->subject = mb_substr($email->subject, 0, 255);
        $this->receivedAt = $email->receivedAt;
        $this->status = $status->value;
        $this->error = $error === null ? null : mb_substr($error, 0, 1000);
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function getStatus(): ProcessedEmailStatus
    {
        return ProcessedEmailStatus::from($this->status);
    }

    public function getOffersCount(): int
    {
        return $this->offersCount;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }
}
