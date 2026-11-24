<?php

declare(strict_types=1);

namespace App\Tests\Integration\Matching;

use App\Entity\JobMatch;
use App\DTO\ContractType;
use App\Matching\MatchVerdict;
use App\AI\Extraction\JobRequirements;
use App\DTO\PublicationDateConfidence;
use App\Profile\InvalidProfileException;

final class JobMatcherTest extends MatchingIntegrationTestCase
{
    public function testCreatesOneResultPerEnabledSearchProfile(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob('Développeur back-end PHP', $this->backendRequirements(), $this->now->modify('-2 hours'));

        $computed = $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        self::assertCount(2, $computed);
        $byProfile = [];
        foreach ($this->matches->findByJob($job) as $match) {
            $byProfile[$match->getSearchProfileId()] = $match;
        }

        self::assertSame(['backend', 'devops'], array_keys($byProfile) === ['backend', 'devops'] ? ['backend', 'devops'] : array_keys($byProfile));
        $backend = $byProfile['backend'];
        self::assertSame(MatchVerdict::Eligible, $backend->getVerdict());
        self::assertGreaterThanOrEqual(90, $backend->getMatchScore());
        self::assertSame(100, $backend->getFreshnessScore(), 'Publiée il y a 2 h, date fiable : fraîcheur maximale.');
        self::assertGreaterThan($byProfile['devops']->getPriorityScore(), $backend->getPriorityScore(), 'Le profil le plus pertinent est le backend.');
        self::assertNotNull($backend->getResult());
        self::assertSame('backend', $backend->getResult()->searchProfileId);
        self::assertSame(1, $this->matches->count(['searchProfileId' => 'backend']), 'Une offre, un résultat par profil : pas de doublon.');
    }

    public function testIsIdempotentAndSkipsUpToDateResults(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());

        self::assertCount(2, $this->matcher()->matchJob($job, $this->now));
        $this->entityManager->flush();

        self::assertSame([], $this->matcher()->matchJob($job, $this->now->modify('+1 hour')), 'Rien n\'a changé : rien n\'est recalculé.');
        self::assertSame(2, $this->matches->count([]));
    }

    public function testForceRecomputesEverything(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        $recomputed = $this->matcher()->matchJob($job, $this->now->modify('+1 hour'), true);

        self::assertCount(2, $recomputed);
        self::assertSame(2, $this->matches->count([]), 'Mis à jour sur place, jamais dupliqués.');
    }

    public function testAChangedProfileRecomputesWithoutAnyLlmCall(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();
        $before = $this->matches->findOneByJobAndProfile($job, 'backend');
        self::assertNotNull($before);
        $scoreBefore = $before->getMatchScore();

        // Le candidat retire Symfony de ses compétences : le matching change, sans réanalyser l'annonce.
        $this->writeProfile(str_replace('skills: [PHP, Symfony, Docker]', 'skills: [PHP, Docker]', self::CANDIDATE));
        $this->matcher()->reset();
        $recomputed = $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        self::assertCount(2, $recomputed);
        $after = $this->matches->findOneByJobAndProfile($job, 'backend');
        self::assertLessThan($scoreBefore, $after?->getMatchScore());
        self::assertSame(['Symfony'], $after->getResult()?->missingRequiredSkills);
        self::assertSame(0, $this->llm->calls, 'Le matching ne sollicite jamais le LLM.');
    }

    public function testChangingOnlyOneSearchProfileRecomputesOnlyThatProfile(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        $this->writeProfile(self::CANDIDATE, str_replace('min_match_score: 60', 'min_match_score: 95', self::SEARCH_PROFILES));
        $this->matcher()->reset();
        $recomputed = $this->matcher()->matchJob($job, $this->now);

        self::assertCount(2, $recomputed, 'Les deux profils ont changé de seuil.');

        $this->writeProfile(self::CANDIDATE, str_replace('primary_skills: [Docker]', 'primary_skills: [Docker, Kubernetes]', str_replace('min_match_score: 60', 'min_match_score: 95', self::SEARCH_PROFILES)));
        $this->matcher()->reset();
        $this->entityManager->flush();
        $onlyOne = $this->matcher()->matchJob($job, $this->now);

        self::assertSame(['devops'], array_map(static fn (JobMatch $match): string => $match->getSearchProfileId(), $onlyOne));
    }

    public function testAReanalysedJobBecomesStale(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        $job->recordAnalysis($job->getRequirements() === null ? throw new \LogicException() : new \App\DTO\AiAnalysisDto(['php'], ContractType::Cdi, false, false, 'non précisé', false, \App\DTO\Seniority::Senior), 70, [], false, $this->now->modify('+1 day'), null);
        $job->setRequirements(new JobRequirements(requiredSkills: ['PHP']));
        $this->entityManager->flush();

        self::assertCount(2, $this->matcher()->matchJob($job, $this->now->modify('+2 days')));
    }

    public function testDoesNothingWithoutExtractedRequirements(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob();

        self::assertSame([], $this->matcher()->matchJob($job, $this->now));
        self::assertSame(0, $this->matches->count([]));
    }

    public function testDoesNothingWithoutCandidateOrSearchProfile(): void
    {
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());

        self::assertSame([], $this->matcher()->matchJob($job, $this->now), 'Aucun profil : le pipeline fonctionne comme avant.');

        file_put_contents($this->profileDirectory . '/search_profiles.yaml', self::SEARCH_PROFILES);
        $this->matcher()->reset();
        self::assertSame([], $this->matcher()->matchJob($job, $this->now), 'Profils de recherche sans candidat.');

        $this->writeProfile(self::CANDIDATE, "search_profiles:\n  backend:\n    enabled: false\n    keywords: [php]\n");
        $this->matcher()->reset();
        self::assertSame([], $this->matcher()->matchJob($job, $this->now), 'Aucun profil actif.');
    }

    public function testAnInvalidProfileFileIsReported(): void
    {
        $this->writeProfile("skils: [PHP]\n");
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());

        $this->expectException(InvalidProfileException::class);
        $this->matcher()->matchJob($job, $this->now);
    }

    public function testResultsOfDisabledProfilesAreRemovedAsObsolete(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        $this->writeProfile(self::CANDIDATE, str_replace("  devops:\n", "  devops:\n    enabled: false\n", self::SEARCH_PROFILES));
        $this->matcher()->reset();

        self::assertSame(1, $this->matcher()->removeObsolete());
        self::assertSame(['backend'], array_map(static fn (JobMatch $match): string => $match->getSearchProfileId(), $this->matches->findAll()));
    }

    public function testRefreshingPrioritiesFollowsTheTimeWithoutRedoingTheMatching(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements(), publishedAt: $this->now->modify('-2 hours'));
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();
        $match = $this->matches->findOneByJobAndProfile($job, 'backend');
        self::assertNotNull($match);
        [$matchScore, $freshness, $priority, $computedAt] = [$match->getMatchScore(), $match->getFreshnessScore(), $match->getPriorityScore(), $match->getComputedAt()];

        $updated = $this->matcher()->refreshPriorities($this->matches->findBatchAfterId(0, 50), $this->now->modify('+20 days'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(2, $updated);
        $after = $this->matches->findOneBy(['searchProfileId' => 'backend']);
        self::assertSame($matchScore, $after?->getMatchScore(), 'La compatibilité ne dépend pas du temps.');
        self::assertLessThan($freshness, $after->getFreshnessScore());
        self::assertSame(5, $after->getFreshnessScore(), 'Plus de 14 jours.');
        self::assertLessThan($priority, $after->getPriorityScore());
        self::assertSame($computedAt->format('c'), $after->getComputedAt()->format('c'), 'Le matching n\'est pas refait.');
    }

    public function testAJobWithAnUnknownDateIsNeverGivenTheMaximumFreshness(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        self::assertSame(25, $this->matches->findOneByJobAndProfile($job, 'backend')?->getFreshnessScore());
    }

    public function testAnUnreliableDateCapsTheFreshness(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements(), publishedAt: $this->now->modify('-1 hour'), confidence: PublicationDateConfidence::Low);
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        self::assertSame(50, $this->matches->findOneByJobAndProfile($job, 'backend')?->getFreshnessScore());
    }

    public function testAFreshIncompatibleJobStaysBehindAnOlderCompatibleOne(): void
    {
        $this->writeProfile();
        $compatible = $this->persistAnalyzedJob('Développeur back-end PHP', $this->backendRequirements(), $this->now->modify('-10 days'), url: 'https://jobs.example/old');
        $incompatible = $this->persistAnalyzedJob('Développeur COBOL', new JobRequirements(requiredSkills: ['COBOL', 'Fortran'], contractTypes: [ContractType::Cdi]), $this->now->modify('-1 hour'), url: 'https://jobs.example/fresh');
        $this->matcher()->matchJob($compatible, $this->now);
        $this->matcher()->matchJob($incompatible, $this->now);
        $this->entityManager->flush();

        $old = $this->matches->findOneByJobAndProfile($compatible, 'backend');
        $fresh = $this->matches->findOneByJobAndProfile($incompatible, 'backend');

        self::assertGreaterThan($fresh?->getPriorityScore(), $old?->getPriorityScore());
        self::assertSame(100, $fresh->getFreshnessScore());
        self::assertNotSame(MatchVerdict::Eligible, $fresh->getVerdict());
        self::assertLessThanOrEqual($fresh->getMatchScore(), $fresh->getPriorityScore());
    }

    public function testAnEliminatedJobHasNoPriorityAndIsNotListed(): void
    {
        $this->writeProfile(self::CANDIDATE, "search_profiles:\n  backend:\n    titles: [\"Développeur back-end\"]\n    excluded_keywords: [alternance]\n");
        $job = $this->persistAnalyzedJob('Développeur back-end PHP', $this->backendRequirements(), $this->now, description: 'Poste en alternance');
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        $match = $this->matches->findOneByJobAndProfile($job, 'backend');

        self::assertSame(MatchVerdict::Eliminated, $match?->getVerdict());
        self::assertSame(0, $match->getPriorityScore());
        self::assertSame([], $this->matches->findTop(10));
        self::assertCount(1, $this->matches->findTop(10, null, false));
    }

    public function testTheTopListKeepsEachJobOnlyForItsBestProfile(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob('Développeur back-end PHP', $this->backendRequirements(), $this->now);
        $other = $this->persistAnalyzedJob('Ingénieur DevOps', new JobRequirements(requiredSkills: ['Docker'], niceSkills: ['PHP'], contractTypes: [ContractType::Cdi]), $this->now, url: 'https://jobs.example/devops');
        $this->matcher()->matchJob($job, $this->now);
        $this->matcher()->matchJob($other, $this->now);
        $this->entityManager->flush();

        $top = $this->matches->findTop(10);

        $perJob = [];
        foreach ($top as $match) {
            $perJob[(int) $match->getJob()->getId()][] = $match->getSearchProfileId();
        }
        foreach ($perJob as $profiles) {
            self::assertCount(1, $profiles, 'Chaque offre n\'apparaît que pour son meilleur profil.');
        }
        self::assertSame('backend', $perJob[(int) $job->getId()][0]);
        self::assertSame('devops', $perJob[(int) $other->getId()][0]);

        $onlyDevops = $this->matches->findTop(10, 'devops', false);
        self::assertSame(['devops', 'devops'], array_map(static fn (JobMatch $match): string => $match->getSearchProfileId(), $onlyDevops));
    }

    public function testPurgingJobsAlsoRemovesTheirResults(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $job->setCreatedAt($this->now->modify('-60 days'));
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();
        self::assertSame(2, $this->matches->count([]));

        self::assertSame(1, $this->jobs->deleteCreatedBefore($this->now->modify('-30 days')));

        self::assertSame(0, $this->matches->count([]));
    }

    public function testTruncatingJobsAlsoRemovesTheirResults(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        $this->jobs->truncate();

        self::assertSame(0, $this->matches->count([]));
    }

    public function testCountsResultsByVerdict(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $this->matcher()->matchJob($job, $this->now);
        $this->entityManager->flush();

        $counts = $this->matches->countByVerdict();

        self::assertSame(2, array_sum($counts));
        self::assertArrayHasKey('eligible', $counts);
    }
}
