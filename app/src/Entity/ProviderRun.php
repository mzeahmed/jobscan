<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Monitoring\ProviderRunStatus;
use App\Repository\ProviderRunRepository;

/**
 * Trace d'une exécution d'un provider, utilisée pour surveiller la santé des sources.
 */
#[ORM\Entity(repositoryClass: ProviderRunRepository::class)]
#[ORM\Index(name: 'idx_provider_run_provider_started', columns: ['provider', 'started_at'])]
class ProviderRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $provider,
        #[ORM\Column]
        private \DateTimeImmutable $startedAt,
        #[ORM\Column]
        private int $durationMs,
        ProviderRunStatus $status,
        #[ORM\Column]
        private int $fetchedCount,
        #[ORM\Column]
        private int $newCount,
        #[ORM\Column]
        private int $duplicateCount,
        #[ORM\Column]
        private int $errorCount,
        ?string $lastError,
    ) {
        $this->status = $status->value;
        $this->lastError = $lastError === null ? null : mb_substr($lastError, 0, 1000);
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getStatus(): ProviderRunStatus
    {
        return ProviderRunStatus::from($this->status);
    }

    public function getFetchedCount(): int
    {
        return $this->fetchedCount;
    }

    public function getNewCount(): int
    {
        return $this->newCount;
    }

    public function getDuplicateCount(): int
    {
        return $this->duplicateCount;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
