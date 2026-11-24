<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationChannel;
use App\Repository\ApplicationRepository;
use App\Tracking\InvalidApplicationException;

/**
 * Candidature de l'utilisateur à une offre : statut, dates, version de CV, contact, message envoyé, notes.
 *
 * Une offre n'a qu'une candidature. Le statut ne change que par `transitionTo()`, qui impose les transitions
 * valides et un ordre chronologique ; chaque changement est consigné dans l'historique (`ApplicationStatusChange`).
 *
 * Données **personnelles** (le contact d'un recruteur, le message envoyé) : saisies par l'utilisateur, jamais
 * déduites ni complétées, jamais journalisées, jamais transmises à un tiers.
 */
#[ORM\Entity(repositoryClass: ApplicationRepository::class)]
#[ORM\Table(name: 'job_application')]
#[ORM\UniqueConstraint(name: 'uniq_job_application_job', columns: ['job_id'])]
#[ORM\Index(name: 'idx_job_application_status', columns: ['status'])]
class Application
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $status = 'draft';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $searchProfileId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $cvVersionId = null;

    /** Libellé du CV au moment de la candidature : la référence reste lisible même si le profil change. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cvVersionLabel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recruiterName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $recruiterContact = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $channel = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $messageSent = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastContactAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $followUpAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Job::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Job $job,
        #[ORM\Column]
        private \DateTimeImmutable $statusChangedAt,
    ) {
        $this->createdAt = $this->statusChangedAt;
        $this->updatedAt = $this->statusChangedAt;
    }

    /**
     * Passe la candidature à un nouveau statut.
     *
     * @param \DateTimeImmutable $at Date réelle du changement (il peut être consigné après coup)
     * @throws InvalidApplicationException transition interdite, ou date antérieure au changement précédent
     */
    public function transitionTo(ApplicationStatus $to, \DateTimeImmutable $at, ?string $note = null): ApplicationStatusChange
    {
        $from = $this->getStatus();

        if (!$from->canTransitionTo($to)) {
            throw new InvalidApplicationException(sprintf(
                'Transition interdite : %s → %s. %s',
                $from->label(),
                $to->label(),
                $from->isTerminal()
                    ? sprintf('« %s » est un statut final.', $from->label())
                    : 'Statuts possibles : ' . implode(', ', array_map(static fn (ApplicationStatus $status): string => $status->label(), $from->allowedTransitions())) . '.',
            ));
        }

        // La création du brouillon n'est qu'une écriture interne : on peut consigner après coup une candidature plus ancienne.
        if ($from !== ApplicationStatus::Draft && $at < $this->statusChangedAt) {
            throw new InvalidApplicationException(sprintf(
                'La date (%s) précède le changement de statut précédent (%s).',
                $at->format('Y-m-d H:i'),
                $this->statusChangedAt->format('Y-m-d H:i'),
            ));
        }

        if ($to === ApplicationStatus::Applied) {
            $this->appliedAt ??= $at;
        }
        if ($to->isResponse()) {
            $this->respondedAt ??= $at;
        }
        if ($to->recordsContact()) {
            $this->lastContactAt = $at;
        }

        $this->status = $to->value;
        $this->statusChangedAt = $at;
        $this->updatedAt = max($this->updatedAt, $at);

        return new ApplicationStatusChange($this, $from, $to, $at, $note);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): Job
    {
        return $this->job;
    }

    public function getStatus(): ApplicationStatus
    {
        return ApplicationStatus::from($this->status);
    }

    public function getSearchProfileId(): ?string
    {
        return $this->searchProfileId;
    }

    public function setSearchProfileId(?string $searchProfileId): void
    {
        $this->searchProfileId = self::text($searchProfileId, 50);
    }

    public function getCvVersionId(): ?string
    {
        return $this->cvVersionId;
    }

    public function getCvVersionLabel(): ?string
    {
        return $this->cvVersionLabel;
    }

    public function setCvVersion(?string $id, ?string $label): void
    {
        $this->cvVersionId = self::text($id, 50);
        $this->cvVersionLabel = $this->cvVersionId === null ? null : self::text($label, 255);
    }

    public function getRecruiterName(): ?string
    {
        return $this->recruiterName;
    }

    public function setRecruiterName(?string $name): void
    {
        $this->recruiterName = self::text($name, 255);
    }

    public function getRecruiterContact(): ?string
    {
        return $this->recruiterContact;
    }

    public function setRecruiterContact(?string $contact): void
    {
        $this->recruiterContact = self::text($contact, 255);
    }

    public function getChannel(): ?ApplicationChannel
    {
        return $this->channel === null ? null : ApplicationChannel::from($this->channel);
    }

    public function setChannel(?ApplicationChannel $channel): void
    {
        $this->channel = $channel?->value;
    }

    public function getMessageSent(): ?string
    {
        return $this->messageSent;
    }

    public function setMessageSent(?string $message): void
    {
        $this->messageSent = self::text($message, 20000);
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = self::text($notes, 20000);
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function getLastContactAt(): ?\DateTimeImmutable
    {
        return $this->lastContactAt;
    }

    public function setLastContactAt(?\DateTimeImmutable $at): void
    {
        $this->lastContactAt = $at;
    }

    public function getFollowUpAt(): ?\DateTimeImmutable
    {
        return $this->followUpAt;
    }

    /**
     * Date prévue de relance, saisie par l'utilisateur. Elle ne peut précéder la date d'envoi.
     *
     * @throws InvalidApplicationException
     */
    public function setFollowUpAt(?\DateTimeImmutable $at): void
    {
        if ($at !== null && $this->appliedAt !== null && $at < $this->appliedAt) {
            throw new InvalidApplicationException('La date de relance précède la date de candidature.');
        }

        $this->followUpAt = $at;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function getStatusChangedAt(): \DateTimeImmutable
    {
        return $this->statusChangedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    private static function text(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
