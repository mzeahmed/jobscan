<?php

declare(strict_types=1);

namespace App\Entity;

use App\Matching\MatchResult;
use App\Matching\MatchVerdict;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\JobMatchRepository;

/**
 * Résultat du matching d'une offre avec un profil de recherche. Une même offre peut avoir un résultat par
 * profil, sans être dupliquée.
 *
 * Donnée **dérivée et recalculable** (à partir des exigences extraites, du profil et des règles) : elle peut
 * être supprimée et recalculée sans nouvel appel au LLM. `fingerprint` identifie le profil et les règles
 * utilisés ; un changement de l'un ou de l'autre rend le résultat périmé.
 */
#[ORM\Entity(repositoryClass: JobMatchRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_job_match_job_profile', columns: ['job_id', 'search_profile_id'])]
#[ORM\Index(name: 'idx_job_match_profile_priority', columns: ['search_profile_id', 'priority_score'])]
class JobMatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $verdict;

    #[ORM\Column]
    private int $matchScore;

    #[ORM\Column]
    private int $freshnessScore;

    #[ORM\Column]
    private int $priorityScore;

    #[ORM\Column]
    private int $coverage;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $cvId;

    /**
     * Résultat complet et explicable (critères, compétences, éliminations, arguments).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $details;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column]
    private int $rulesVersion;

    #[ORM\Column]
    private \DateTimeImmutable $computedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Job::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Job $job,
        #[ORM\Column(length: 50)]
        private string $searchProfileId,
        MatchResult $result,
        int $freshnessScore,
        int $priorityScore,
        string $fingerprint,
        int $rulesVersion,
        \DateTimeImmutable $now,
    ) {
        $this->apply($result, $freshnessScore, $priorityScore, $fingerprint, $rulesVersion, $now);
    }

    public function recompute(MatchResult $result, int $freshnessScore, int $priorityScore, string $fingerprint, int $rulesVersion, \DateTimeImmutable $now): void
    {
        $this->apply($result, $freshnessScore, $priorityScore, $fingerprint, $rulesVersion, $now);
    }

    /** Met à jour fraîcheur et priorité sans refaire le matching (le temps passe, la compatibilité non). */
    public function updatePriority(int $freshnessScore, int $priorityScore): void
    {
        $this->freshnessScore = $freshnessScore;
        $this->priorityScore = $priorityScore;
    }

    private function apply(MatchResult $result, int $freshnessScore, int $priorityScore, string $fingerprint, int $rulesVersion, \DateTimeImmutable $now): void
    {
        $this->verdict = $result->verdict->value;
        $this->matchScore = $result->matchScore;
        $this->freshnessScore = $freshnessScore;
        $this->priorityScore = $priorityScore;
        $this->coverage = $result->coverage;
        $this->cvId = $result->cvId;
        $this->details = $result->toArray();
        $this->fingerprint = $fingerprint;
        $this->rulesVersion = $rulesVersion;
        $this->computedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): Job
    {
        return $this->job;
    }

    public function getSearchProfileId(): string
    {
        return $this->searchProfileId;
    }

    public function getVerdict(): MatchVerdict
    {
        return MatchVerdict::from($this->verdict);
    }

    public function getMatchScore(): int
    {
        return $this->matchScore;
    }

    public function getFreshnessScore(): int
    {
        return $this->freshnessScore;
    }

    public function getPriorityScore(): int
    {
        return $this->priorityScore;
    }

    public function getCoverage(): int
    {
        return $this->coverage;
    }

    public function getCvId(): ?string
    {
        return $this->cvId;
    }

    public function getResult(): ?MatchResult
    {
        return MatchResult::fromArray($this->details);
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getRulesVersion(): int
    {
        return $this->rulesVersion;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }
}
