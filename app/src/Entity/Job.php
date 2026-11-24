<?php

declare(strict_types=1);

namespace App\Entity;

use App\DTO\JobDto;
use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use App\DTO\AnalysisStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\JobRepository;
use App\DTO\PublicationDateSource;
use App\DTO\PublicationDateConfidence;

#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Index(name: 'idx_job_score', columns: ['score'])]
#[ORM\Index(name: 'idx_job_source', columns: ['source'])]
#[ORM\Index(name: 'idx_job_created_at', columns: ['created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_job_url', columns: ['url'])]
#[ORM\UniqueConstraint(name: 'uniq_job_canonical_url', columns: ['canonical_url'])]
#[ORM\Index(name: 'idx_job_fingerprint', columns: ['fingerprint'])]
#[ORM\Index(name: 'idx_job_analysis', columns: ['analysis_status', 'next_analysis_attempt_at'])]
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

    /** Première détection par Jobscan (≠ date de publication). */
    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    /** Dernière fois qu'un provider a remonté cette offre. */
    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $analyzedAt = null;

    #[ORM\Column(length: 20)]
    private string $publicationDateSource = 'unknown';

    #[ORM\Column(length: 10)]
    private string $publicationDateConfidence = 'unknown';

    #[ORM\Column(length: 10)]
    private string $analysisStatus = 'pending';

    #[ORM\Column]
    private int $analysisAttempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAnalysisAttemptAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextAnalysisAttemptAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastAnalysisError = null;

    /** Offre probablement identique (même titre, entreprise, lieu) : simple indice, jamais une fusion. */
    #[ORM\Column(nullable: true)]
    private ?int $probableDuplicateOfId = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->firstSeenAt = $this->createdAt;
        $this->lastSeenAt = $this->createdAt;
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
        $job->publicationDateSource = $dto->publicationDateSource->value;
        $job->publicationDateConfidence = $dto->publicationDateConfidence->value;
        $job->score = 0;

        return $job;
    }

    public function toDto(): JobDto
    {
        return new JobDto(
            title: (string) $this->title,
            url: (string) $this->url,
            description: (string) $this->description,
            source: (string) $this->source,
            publishedAt: $this->publishedAt,
            company: $this->company,
            location: $this->location,
            publicationDateSource: $this->getPublicationDateSource(),
            publicationDateConfidence: $this->getPublicationDateConfidence(),
        );
    }

    /**
     * Enregistre une nouvelle observation de l'offre par un provider.
     *
     * Met à jour `lastSeenAt` et fusionne la date de publication : la plus fiable
     * l'emporte ; à fiabilité égale, la plus ancienne est conservée afin qu'une
     * republication ne réinitialise pas la première publication connue.
     */
    public function registerSighting(JobDto $dto, \DateTimeImmutable $now): void
    {
        $this->lastSeenAt = $now;
        $this->mergePublicationDate($dto->publishedAt, $dto->publicationDateSource, $dto->publicationDateConfidence);
    }

    public function mergePublicationDate(
        ?\DateTimeImmutable $publishedAt,
        PublicationDateSource $source,
        PublicationDateConfidence $confidence,
    ): void {
        if ($publishedAt === null) {
            return;
        }

        $current = $this->getPublicationDateConfidence();
        $moreReliable = $this->publishedAt === null || $confidence->rank() > $current->rank();
        $sameReliabilityButEarlier = $confidence->rank() === $current->rank() && $publishedAt < $this->publishedAt;

        if ($moreReliable || $sameReliabilityButEarlier) {
            $this->publishedAt = $publishedAt;
            $this->publicationDateSource = $source->value;
            $this->publicationDateConfidence = $confidence->value;
        }
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

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->analyzedAt;
    }

    public function getPublicationDateSource(): PublicationDateSource
    {
        return PublicationDateSource::from($this->publicationDateSource);
    }

    public function getPublicationDateConfidence(): PublicationDateConfidence
    {
        return PublicationDateConfidence::from($this->publicationDateConfidence);
    }

    public function getAnalysisStatus(): AnalysisStatus
    {
        return AnalysisStatus::from($this->analysisStatus);
    }

    public function getAnalysisAttempts(): int
    {
        return $this->analysisAttempts;
    }

    public function getLastAnalysisAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAnalysisAttemptAt;
    }

    public function getNextAnalysisAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAnalysisAttemptAt;
    }

    public function getLastAnalysisError(): ?string
    {
        return $this->lastAnalysisError;
    }

    public function getProbableDuplicateOfId(): ?int
    {
        return $this->probableDuplicateOfId;
    }

    public function markProbableDuplicateOf(int $jobId): void
    {
        $this->probableDuplicateOfId = $jobId;
    }

    /**
     * Enregistre le résultat d'une analyse (LLM ou heuristique).
     *
     * Une analyse heuristique (`$usedFallback`) reste à retenter tant que
     * `$nextAttemptAt` est défini ; le LLM pourra la remplacer ultérieurement.
     *
     * @param list<string> $breakdown
     */
    public function recordAnalysis(
        AiAnalysisDto $analysis,
        int $score,
        array $breakdown,
        bool $usedFallback,
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $nextAttemptAt,
    ): void {
        $this->setAnalysis($analysis, $breakdown);
        $this->score = $score;
        $this->analysisStatus = ($usedFallback ? AnalysisStatus::Fallback : AnalysisStatus::Done)->value;
        $this->analyzedAt = $now;
        $this->lastAnalysisAttemptAt = $now;
        ++$this->analysisAttempts;
        $this->nextAnalysisAttemptAt = $usedFallback ? $nextAttemptAt : null;
        $this->lastAnalysisError = null;
    }

    public function recordAnalysisFailure(string $error, \DateTimeImmutable $now, ?\DateTimeImmutable $nextAttemptAt): void
    {
        $this->analysisStatus = AnalysisStatus::Failed->value;
        $this->lastAnalysisAttemptAt = $now;
        ++$this->analysisAttempts;
        $this->nextAnalysisAttemptAt = $nextAttemptAt;
        $this->lastAnalysisError = mb_substr($error, 0, 500);
    }

    /**
     * Une offre peut être notifiée une fois son analyse définitive : faite par le LLM,
     * ou heuristique sans nouvelle tentative prévue. Une offre en attente ou en échec
     * n'a pas de score de compatibilité fiable.
     */
    public function isAnalysisFinal(): bool
    {
        return match ($this->getAnalysisStatus()) {
            AnalysisStatus::Done => true,
            AnalysisStatus::Fallback => $this->nextAnalysisAttemptAt === null,
            AnalysisStatus::Pending, AnalysisStatus::Failed => false,
        };
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
