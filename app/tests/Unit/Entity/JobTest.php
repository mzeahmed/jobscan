<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\DTO\JobDto;
use App\Entity\Job;
use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use App\DTO\AnalysisStatus;
use PHPUnit\Framework\TestCase;
use App\DTO\PublicationDateSource;
use App\AI\Extraction\JobRequirements;
use App\DTO\PublicationDateConfidence;

final class JobTest extends TestCase
{
    public function testANewJobIsPendingAndKeepsUnknownDatesUnknown(): void
    {
        $job = Job::fromDTO(new JobDto('PHP', 'https://example.test/1', 'PHP', 'test'));

        self::assertSame(AnalysisStatus::Pending, $job->getAnalysisStatus());
        self::assertNull($job->getPublishedAt());
        self::assertSame(PublicationDateConfidence::Unknown, $job->getPublicationDateConfidence());
        self::assertFalse($job->isAnalysisFinal());
        self::assertNull($job->getAnalyzedAt());
    }

    public function testAMoreReliableDateReplacesAWeakerOne(): void
    {
        $job = Job::fromDTO($this->dto(new \DateTimeImmutable('-1 day'), PublicationDateConfidence::Low));

        $job->mergePublicationDate(new \DateTimeImmutable('-2 days'), PublicationDateSource::Api, PublicationDateConfidence::High);

        self::assertSame(PublicationDateConfidence::High, $job->getPublicationDateConfidence());
        self::assertSame(PublicationDateSource::Api, $job->getPublicationDateSource());
        self::assertSame(new \DateTimeImmutable('-2 days')->format('Y-m-d'), $job->getPublishedAt()?->format('Y-m-d'));
    }

    public function testALessReliableDateNeverOverridesAReliableOne(): void
    {
        $job = Job::fromDTO($this->dto(new \DateTimeImmutable('-3 days'), PublicationDateConfidence::High));

        $job->mergePublicationDate(new \DateTimeImmutable('-10 days'), PublicationDateSource::Text, PublicationDateConfidence::Low);

        self::assertSame(PublicationDateConfidence::High, $job->getPublicationDateConfidence());
        self::assertSame(new \DateTimeImmutable('-3 days')->format('Y-m-d'), $job->getPublishedAt()?->format('Y-m-d'));
    }

    public function testAtEqualReliabilityTheEarliestDateWins(): void
    {
        $job = Job::fromDTO($this->dto(new \DateTimeImmutable('-1 day'), PublicationDateConfidence::Medium));

        $job->mergePublicationDate(new \DateTimeImmutable('-4 days'), PublicationDateSource::Feed, PublicationDateConfidence::Medium);
        $job->mergePublicationDate(new \DateTimeImmutable('-2 days'), PublicationDateSource::Feed, PublicationDateConfidence::Medium);

        self::assertSame(new \DateTimeImmutable('-4 days')->format('Y-m-d'), $job->getPublishedAt()?->format('Y-m-d'));
    }

    public function testAnUnknownDateNeverErasesAKnownOne(): void
    {
        $job = Job::fromDTO($this->dto(new \DateTimeImmutable('-3 days'), PublicationDateConfidence::Medium));

        $job->mergePublicationDate(null, PublicationDateSource::Unknown, PublicationDateConfidence::Unknown);

        self::assertNotNull($job->getPublishedAt());
    }

    public function testSightingUpdatesLastSeenButNotFirstSeen(): void
    {
        $job = Job::fromDTO($this->dto(null, PublicationDateConfidence::Unknown));
        $first = $job->getFirstSeenAt();
        $later = $first->modify('+3 hours');

        $job->registerSighting($this->dto(null, PublicationDateConfidence::Unknown), $later);

        self::assertEquals($first, $job->getFirstSeenAt());
        self::assertEquals($later, $job->getLastSeenAt());
    }

    public function testAHeuristicAnalysisStaysRetryableUntilAttemptsRunOut(): void
    {
        $job = Job::fromDTO($this->dto(null, PublicationDateConfidence::Unknown));
        $now = new \DateTimeImmutable();

        $job->recordAnalysis($this->analysis(), 55, [], true, $now, $now->modify('+15 minutes'));
        self::assertSame(AnalysisStatus::Fallback, $job->getAnalysisStatus());
        self::assertFalse($job->isAnalysisFinal());
        self::assertSame(1, $job->getAnalysisAttempts());

        $job->recordAnalysis($this->analysis(), 65, [], false, $now, null);
        self::assertSame(AnalysisStatus::Done, $job->getAnalysisStatus());
        self::assertTrue($job->isAnalysisFinal());
        self::assertNull($job->getNextAnalysisAttemptAt());
        self::assertSame(2, $job->getAnalysisAttempts());
        self::assertSame(65, $job->getScore());
    }

    public function testAHeuristicAnalysisWithoutRetryLeftIsFinal(): void
    {
        $job = Job::fromDTO($this->dto(null, PublicationDateConfidence::Unknown));

        $job->recordAnalysis($this->analysis(), 55, [], true, new \DateTimeImmutable(), null);

        self::assertTrue($job->isAnalysisFinal());
    }

    public function testAFailureIsRecordedWithoutBecomingFinal(): void
    {
        $job = Job::fromDTO($this->dto(null, PublicationDateConfidence::Unknown));
        $now = new \DateTimeImmutable();

        $job->recordAnalysisFailure(str_repeat('x', 800), $now, $now->modify('+15 minutes'));

        self::assertSame(AnalysisStatus::Failed, $job->getAnalysisStatus());
        self::assertFalse($job->isAnalysisFinal());
        self::assertSame(500, mb_strlen((string) $job->getLastAnalysisError()));
        self::assertNotNull($job->getNextAnalysisAttemptAt());
    }

    public function testRequirementsAreStoredWithTheirSchemaVersion(): void
    {
        $job = Job::fromDTO($this->dto(null, PublicationDateConfidence::Unknown));
        self::assertNull($job->getRequirements());
        self::assertNull($job->getExtractionVersion());

        $job->setRequirements(new JobRequirements(requiredSkills: ['PHP'], seniority: Seniority::Senior));

        self::assertSame(['PHP'], $job->getRequirements()?->requiredSkills);
        self::assertSame(Seniority::Senior, $job->getRequirements()->seniority);
        self::assertSame(JobRequirements::SCHEMA_VERSION, $job->getExtractionVersion());
    }

    public function testReextractionEnrichesWithoutChangingTheAnalysisState(): void
    {
        $job = Job::fromDTO($this->dto(null, PublicationDateConfidence::Unknown));
        $before = new \DateTimeImmutable('-2 days');
        $job->recordAnalysis($this->analysis(), 40, [], false, $before, null);
        $job->markAsNotified();
        $notifiedAt = $job->getNotifiedAt();
        $now = new \DateTimeImmutable();

        $job->applyReextraction($this->analysis(), 70, ['+30 (symfony stack)'], new JobRequirements(requiredSkills: ['Symfony']), $now);

        self::assertSame(70, $job->getScore());
        self::assertSame(['+30 (symfony stack)'], $job->getScoreBreakdown());
        self::assertSame(['Symfony'], $job->getRequirements()?->requiredSkills);
        self::assertEquals($now, $job->getAnalyzedAt());
        self::assertSame(AnalysisStatus::Done, $job->getAnalysisStatus());
        self::assertSame(1, $job->getAnalysisAttempts());
        self::assertEquals($notifiedAt, $job->getNotifiedAt());
    }

    private function dto(?\DateTimeImmutable $date, PublicationDateConfidence $confidence): JobDto
    {
        return new JobDto(
            'PHP',
            'https://example.test/1',
            'PHP',
            'test',
            $date,
            publicationDateSource: $date === null ? PublicationDateSource::Unknown : PublicationDateSource::Feed,
            publicationDateConfidence: $confidence,
        );
    }

    private function analysis(): AiAnalysisDto
    {
        return new AiAnalysisDto(['php'], ContractType::Unknown, false, false, 'non précisé', false, Seniority::Unknown);
    }
}
