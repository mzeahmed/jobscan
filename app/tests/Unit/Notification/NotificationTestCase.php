<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\DTO\JobDto;
use App\Entity\Job;
use App\DTO\Seniority;
use App\Entity\JobMatch;
use App\DTO\ContractType;
use App\Matching\MatchResult;
use App\Matching\MatchVerdict;
use PHPUnit\Framework\TestCase;
use App\Matching\CriterionResult;
use App\Matching\CriterionStatus;
use App\AI\Extraction\SalaryRange;
use App\DTO\PublicationDateSource;
use App\Profile\Model\SalaryPeriod;
use App\AI\Extraction\JobRequirements;
use App\DTO\PublicationDateConfidence;
use App\AI\Extraction\ExtractionSource;
use App\Profile\Model\RemotePreference;

abstract class NotificationTestCase extends TestCase
{
    protected \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-12-01 12:00:00');
    }

    protected function job(
        string $title = 'Développeur back-end PHP',
        ?JobRequirements $requirements = null,
        ?\DateTimeImmutable $publishedAt = null,
        PublicationDateConfidence $confidence = PublicationDateConfidence::High,
        ?string $company = 'Exemple SAS',
        ?string $location = 'Marseille',
        string $url = 'https://jobs.example/offre-1',
    ): Job {
        $job = Job::fromDTO(new JobDto(
            $title,
            $url,
            'Description',
            'test',
            $publishedAt,
            company: $company,
            location: $location,
            publicationDateSource: $publishedAt === null ? PublicationDateSource::Unknown : PublicationDateSource::Api,
            publicationDateConfidence: $publishedAt === null ? PublicationDateConfidence::Unknown : $confidence,
        ));
        $job->setRequirements($requirements ?? new JobRequirements(
            requiredSkills: ['PHP', 'Symfony'],
            seniority: Seniority::Senior,
            remoteMode: RemotePreference::Remote,
            contractTypes: [ContractType::Cdi],
            salary: new SalaryRange(50000, 60000, 'EUR', SalaryPeriod::Annual),
        ));

        return $job;
    }

    /**
     * @param list<CriterionResult> $criteria
     * @param list<string> $missing
     * @param list<string> $unknown
     */
    protected function match(
        Job $job,
        int $match = 92,
        int $freshness = 100,
        int $priority = 94,
        array $criteria = [],
        array $missing = [],
        array $unknown = [],
        ExtractionSource $source = ExtractionSource::Llm,
        string $profile = 'backend',
        ?string $cv = 'backend-php',
    ): JobMatch {
        $result = new MatchResult($profile, MatchVerdict::Eligible, $match, 90, $criteria, [], $missing, [], [], $unknown, [], $cv, $source);

        return new JobMatch($job, $profile, $result, $freshness, $priority, 'fp', 1, $this->now);
    }

    protected function criterion(string $name, CriterionStatus $status, float $score, int $weight, string $detail): CriterionResult
    {
        return new CriterionResult($name, $status, $score, $weight, $detail);
    }
}
