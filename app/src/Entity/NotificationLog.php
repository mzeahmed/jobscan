<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Notification\NotificationLevel;
use App\Repository\NotificationLogRepository;

/**
 * Trace d'une notification envoyée pour une offre : c'est elle qui garantit qu'une offre n'est notifiée
 * qu'une seule fois, qu'elle l'ait été immédiatement ou dans un récapitulatif.
 */
#[ORM\Entity(repositoryClass: NotificationLogRepository::class)]
#[ORM\Index(name: 'idx_notification_log_sent_at', columns: ['sent_at'])]
class NotificationLog
{
    public const string KIND_IMMEDIATE = 'immediate';
    public const string KIND_DIGEST = 'digest';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $level;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Job::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Job $job,
        #[ORM\Column(length: 10)]
        private string $kind,
        NotificationLevel $level,
        #[ORM\Column(length: 50, nullable: true)]
        private ?string $searchProfileId,
        #[ORM\Column]
        private int $priorityScore,
        #[ORM\Column]
        private \DateTimeImmutable $sentAt,
    ) {
        $this->level = $level->value;
    }

    public function getJob(): Job
    {
        return $this->job;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getLevel(): NotificationLevel
    {
        return NotificationLevel::from($this->level);
    }

    public function getSearchProfileId(): ?string
    {
        return $this->searchProfileId;
    }

    public function getPriorityScore(): int
    {
        return $this->priorityScore;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }
}
