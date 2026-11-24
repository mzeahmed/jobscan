<?php

declare(strict_types=1);

namespace App\Tests\Integration\Matching;

use App\DTO\JobDto;
use App\Entity\Job;
use App\DTO\AnalysisStatus;
use App\Matching\MatchVerdict;
use App\AI\Extraction\ExtractionSource;
use App\Analysis\PendingAnalysisRunner;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le matching suit l'analyse : une offre analysée est appariée dans la foulée, sans jamais gêner l'analyse.
 */
final class AnalysisMatchingTest extends MatchingIntegrationTestCase
{
    private const string ADVERT = 'Développeur back-end PHP Symfony en CDI, télétravail complet, 4 ans d\'expérience minimum. Docker apprécié.';

    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->set('http_client', new MockHttpClient(new MockResponse('{"ok":true}')));
        $this->llm->default = json_encode([
            'required_skills' => ['PHP', 'Symfony'],
            'nice_skills' => ['Docker'],
            'min_years_experience' => 4,
            'remote_mode' => 'remote',
            'contract_types' => ['cdi'],
        ]);
    }

    public function testAnalysedJobsAreMatchedRightAway(): void
    {
        $this->writeProfile();
        $this->persistPending();

        $summary = $this->runner()->run(10);

        self::assertSame(1, $summary->analyzedByAi);
        $job = $this->jobs->findOneBy([]);
        $matches = $this->matches->findByJob($job);
        self::assertCount(2, $matches);
        self::assertSame('backend', $matches[0]->getSearchProfileId());
        self::assertSame(MatchVerdict::Eligible, $matches[0]->getVerdict());
        self::assertSame(1, $this->llm->calls, 'Un seul appel LLM, quel que soit le nombre de profils.');
    }

    public function testWithoutAnyProfileTheAnalysisWorksAsBefore(): void
    {
        $this->persistPending();

        $summary = $this->runner()->run(10);

        self::assertSame(1, $summary->analyzedByAi);
        self::assertSame(AnalysisStatus::Done, $this->jobs->findOneBy([])->getAnalysisStatus());
        self::assertSame(0, $this->matches->count([]));
    }

    public function testAnInvalidProfileNeverBlocksTheAnalysis(): void
    {
        $this->writeProfile("skils: [PHP]\n");
        $this->persistPending();

        $summary = $this->runner()->run(10);

        self::assertSame(1, $summary->analyzedByAi);
        $job = $this->jobs->findOneBy([]);
        self::assertSame(AnalysisStatus::Done, $job->getAnalysisStatus());
        self::assertNotNull($job->getRequirements(), 'Les exigences extraites sont conservées.');
        self::assertSame(0, $this->matches->count([]));
    }

    public function testAHeuristicAnalysisIsMatchedButFlaggedAsSuch(): void
    {
        $this->writeProfile();
        $this->llm->default = null;
        $this->persistPending();

        $this->runner()->run(10);

        $matches = $this->matches->findByJob($this->jobs->findOneBy([]));
        self::assertCount(2, $matches);
        $result = $matches[0]->getResult();
        self::assertSame(ExtractionSource::Heuristic, $result?->requirementsSource);
        self::assertContains('Compétences exigées indéterminées (extraction heuristique).', $result->unknown);
    }

    public function testAHeuristicResultIsRecomputedWhenTheLlmComesBack(): void
    {
        $this->writeProfile();
        $this->llm->default = null;
        $this->persistPending();
        $this->runner()->run(10, $this->now);
        $before = $this->matches->findOneBy(['searchProfileId' => 'backend'])?->getResult();

        $this->llm->default = json_encode(['required_skills' => ['PHP', 'Symfony'], 'contract_types' => ['cdi']]);
        $this->runner()->run(10, $this->now->modify('+30 minutes'));
        $after = $this->matches->findOneBy(['searchProfileId' => 'backend'])?->getResult();

        self::assertSame(ExtractionSource::Heuristic, $before?->requirementsSource);
        self::assertSame(ExtractionSource::Llm, $after?->requirementsSource, 'La réanalyse par le LLM rend le résultat périmé : il est recalculé.');
    }

    public function testReextractionMatchesTheEnrichedJobs(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob('Développeur back-end PHP', null, null, description: self::ADVERT);
        self::assertNull($job->getRequirements());
        $this->entityManager->clear();

        $summary = $this->runner()->reextractOutdated(10);

        self::assertSame(1, $summary->analyzedByAi);
        self::assertSame(2, $this->matches->count([]));
    }

    private function runner(): PendingAnalysisRunner
    {
        return self::getContainer()->get(PendingAnalysisRunner::class);
    }

    private function persistPending(): void
    {
        $this->entityManager->persist(Job::fromDTO(new JobDto('Développeur back-end PHP', 'https://jobs.example/pending', self::ADVERT, 'test')));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
