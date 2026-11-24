<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Tracking\ApplicationStatus;
use App\Repository\ApplicationStatusChangeRepository;

/**
 * Une ligne de l'historique d'une candidature : de quel statut à quel statut, quand, et pourquoi.
 */
#[ORM\Entity(repositoryClass: ApplicationStatusChangeRepository::class)]
#[ORM\Table(name: 'job_application_status_change')]
#[ORM\Index(name: 'idx_job_application_status_change_changed_at', columns: ['changed_at'])]
class ApplicationStatusChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $fromStatus;

    #[ORM\Column(length: 20)]
    private string $toStatus;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Application::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Application $application,
        ?ApplicationStatus $from,
        ApplicationStatus $to,
        #[ORM\Column]
        private \DateTimeImmutable $changedAt,
        ?string $note = null,
    ) {
        $this->fromStatus = $from?->value;
        $this->toStatus = $to->value;
        $this->note = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500);
    }

    public function getApplication(): Application
    {
        return $this->application;
    }

    public function getFromStatus(): ?ApplicationStatus
    {
        return $this->fromStatus === null ? null : ApplicationStatus::from($this->fromStatus);
    }

    public function getToStatus(): ApplicationStatus
    {
        return ApplicationStatus::from($this->toStatus);
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
