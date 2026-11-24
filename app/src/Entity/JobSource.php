<?php

declare(strict_types=1);

namespace App\Entity;

use App\DTO\JobDto;
use Doctrine\ORM\Mapping as ORM;
use App\DTO\PublicationDateSource;
use App\DTO\PublicationDateConfidence;
use App\Repository\JobSourceRepository;

/**
 * Une observation d'une offre par un provider : une même offre (`Job`) peut être
 * remontée par plusieurs sources, avec des URLs, identifiants et dates différents.
 */
#[ORM\Entity(repositoryClass: JobSourceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_job_source_provider_url', columns: ['provider', 'canonical_url'])]
#[ORM\UniqueConstraint(name: 'uniq_job_source_external_id', columns: ['provider', 'external_id'])]
#[ORM\Index(name: 'idx_job_source_job', columns: ['job_id'])]
class JobSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $provider;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId;

    /** Date de publication telle que rapportée par ce provider. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reportedPublishedAt;

    #[ORM\Column(length: 20)]
    private string $publicationDateSource;

    #[ORM\Column(length: 10)]
    private string $publicationDateConfidence;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Job::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Job $job,
        JobDto $dto,
        #[ORM\Column(length: 2048)]
        private string $canonicalUrl,
        #[ORM\Column]
        private \DateTimeImmutable $firstSeenAt,
    ) {
        $this->provider = $dto->source;
        $this->url = $dto->url;
        $this->externalId = $dto->externalId;
        $this->reportedPublishedAt = $dto->publishedAt;
        $this->publicationDateSource = $dto->publicationDateSource->value;
        $this->publicationDateConfidence = $dto->publicationDateConfidence->value;
        $this->lastSeenAt = $this->firstSeenAt;
    }

    public function registerSighting(JobDto $dto, \DateTimeImmutable $now): void
    {
        $this->lastSeenAt = $now;

        if ($dto->publishedAt !== null) {
            $this->reportedPublishedAt = $dto->publishedAt;
            $this->publicationDateSource = $dto->publicationDateSource->value;
            $this->publicationDateConfidence = $dto->publicationDateConfidence->value;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): Job
    {
        return $this->job;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getCanonicalUrl(): string
    {
        return $this->canonicalUrl;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getReportedPublishedAt(): ?\DateTimeImmutable
    {
        return $this->reportedPublishedAt;
    }

    public function getPublicationDateSource(): PublicationDateSource
    {
        return PublicationDateSource::from($this->publicationDateSource);
    }

    public function getPublicationDateConfidence(): PublicationDateConfidence
    {
        return PublicationDateConfidence::from($this->publicationDateConfidence);
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
