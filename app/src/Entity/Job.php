<?php

declare(strict_types=1);

namespace App\Entity;

use App\DTO\JobDto;
use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\JobRepository;

#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Index(name: 'idx_job_score', columns: ['score'])]
#[ORM\Index(name: 'idx_job_source', columns: ['source'])]
#[ORM\Index(name: 'idx_job_created_at', columns: ['created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_job_url', columns: ['url'])]
#[ORM\UniqueConstraint(name: 'uniq_job_canonical_url', columns: ['canonical_url'])]
#[ORM\UniqueConstraint(name: 'uniq_job_fingerprint', columns: ['fingerprint'])]
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

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $canonicalUrl = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fingerprint = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $stack = [];

    #[ORM\Column(length: 20)]
    private string $contractType = 'unknown';

    #[ORM\Column]
    private bool $freelance = false;

    #[ORM\Column]
    private bool $remote = false;

    #[ORM\Column(length: 100)]
    private string $budget = 'non précisé';

    #[ORM\Column]
    private bool $recent = false;

    #[ORM\Column(length: 20)]
    private string $seniority = 'unknown';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $scoreBreakdown = [];

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
        $title = preg_replace('/[^a-z0-9\p{L}\s]/u', '', $title);
        $title = preg_replace('/\s+/', ' ', (string) $title);

        return trim((string) $title);
    }

    public static function fromDTO(JobDto $dto): self
    {
        $job = new self();

        $job->title = $dto->title;
        $job->url = $dto->url;
        $job->description = $dto->description;
        $job->source = $dto->source;
        $job->titleHash = sha1(self::normalizeTitle($dto->title));
        $job->company = $dto->company;
        $job->location = $dto->location;
        $job->publishedAt = $dto->publishedAt;

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

    public function setIdentity(string $canonicalUrl, ?string $fingerprint): void
    {
        $this->canonicalUrl = $canonicalUrl;
        $this->fingerprint = $fingerprint;
    }

    /** @param list<string> $breakdown */
    public function setAnalysis(AiAnalysisDto $analysis, array $breakdown): void
    {
        $this->stack = $analysis->stack;
        $this->contractType = $analysis->contractType->value;
        $this->freelance = $analysis->freelance;
        $this->remote = $analysis->remote;
        $this->budget = $analysis->budget;
        $this->recent = $analysis->recent;
        $this->seniority = $analysis->seniority->value;
        $this->scoreBreakdown = $breakdown;
    }

    public function getCanonicalUrl(): ?string
    {
        return $this->canonicalUrl;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /** @return list<string> */
    public function getStack(): array
    {
        return $this->stack;
    }

    public function getContractType(): ContractType
    {
        return ContractType::from($this->contractType);
    }

    public function isFreelance(): bool
    {
        return $this->freelance;
    }

    public function isRemote(): bool
    {
        return $this->remote;
    }

    public function getBudget(): string
    {
        return $this->budget;
    }

    public function isRecent(): bool
    {
        return $this->recent;
    }

    public function getSeniority(): Seniority
    {
        return Seniority::from($this->seniority);
    }

    /** @return list<string> */
    public function getScoreBreakdown(): array
    {
        return $this->scoreBreakdown;
    }
}
