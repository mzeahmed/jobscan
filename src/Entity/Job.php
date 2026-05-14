<?php

declare(strict_types=1);

namespace App\Entity;

use App\DTO\JobDTO;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\JobRepository;

#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Table(
    indexes: [
        new ORM\Index(name: 'idx_job_score', columns: ['score']),
        new ORM\Index(name: 'idx_job_source', columns: ['source']),
        new ORM\Index(name: 'idx_job_created_at', columns: ['created_at']),
    ],
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_job_url', columns: ['url']),
        new ORM\UniqueConstraint(name: 'uniq_job_title_hash', columns: ['title_hash']),
    ],
)]
class Job
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(length: 2048)]
    private ?string $url = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    private ?string $source = null;

    #[ORM\Column]
    private ?int $score = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $titleHash = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function normalizeTitle(string $title): string
    {
        $title = mb_strtolower($title, 'UTF-8');
        $title = (string) preg_replace('/[^a-z0-9\p{L}\s]/u', '', $title);
        $title = (string) preg_replace('/\s+/', ' ', $title);

        return trim($title);
    }

    public static function fromDTO(JobDTO $dto): self
    {
        $job = new self();
        $job->title = $dto->title;
        $job->url = $dto->url;
        $job->description = $dto->description;
        $job->source = $dto->source;
        $job->titleHash = sha1(self::normalizeTitle($dto->title));

        return $job;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function markAsNotified(): void
    {
        $this->notifiedAt = new \DateTimeImmutable();
    }

    public function getTitleHash(): ?string
    {
        return $this->titleHash;
    }
}
