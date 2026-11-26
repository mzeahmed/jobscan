<?php

declare(strict_types=1);

namespace App\Tests\Integration\Matching;

use App\DTO\JobDto;
use App\Entity\Job;
use App\DTO\Seniority;
use App\DTO\ContractType;
use App\DTO\AiAnalysisDto;
use App\Matching\JobMatcher;
use App\Repository\JobRepository;
use App\DTO\PublicationDateSource;
use Doctrine\ORM\Tools\SchemaTool;
use App\AI\Extraction\JobRequirements;
use App\DTO\PublicationDateConfidence;
use App\Repository\JobMatchRepository;
use App\Tests\Support\MutableLlmClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Noyau, base SQLite de test et dossier de profil temporaire (jamais celui de l'utilisateur).
 */
abstract class MatchingIntegrationTestCase extends KernelTestCase
{
    protected const string CANDIDATE = <<<'YAML'
        skills: [PHP, Symfony, Docker]
        excluded_skills: [Java]
        experiences:
          - {company: Exemple SAS, title: Développeur, start: "2015-01", skills: [PHP, Symfony]}
        remote_modes: [remote, hybrid]
        contracts: [cdi]
        YAML;

    protected const string SEARCH_PROFILES = <<<'YAML'
        search_profiles:
          backend:
            titles: ["Développeur back-end"]
            primary_skills: [PHP]
            min_match_score: 60
          devops:
            titles: ["Ingénieur DevOps"]
            primary_skills: [Docker]
            min_match_score: 60
        YAML;

    protected string $profileDirectory;
    protected EntityManagerInterface $entityManager;
    protected JobRepository $jobs;
    protected JobMatchRepository $matches;
    protected MutableLlmClient $llm;
    protected \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable();
        $this->profileDirectory = sys_get_temp_dir() . '/jobscan-matching-' . bin2hex(random_bytes(6));
        mkdir($this->profileDirectory, 0o700, true);
        $this->useProfileDirectory($this->profileDirectory);

        self::bootKernel();
        $container = self::getContainer();
        $this->llm = new MutableLlmClient();
        $container->set('llm.client', $this->llm);
        $container->get('cache.app')->clear();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->jobs = $container->get(JobRepository::class);
        $this->matches = $container->get(JobMatchRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        foreach (glob($this->profileDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->profileDirectory);
        $this->useProfileDirectory('/nonexistent-jobscan-profile');

        parent::tearDown();
    }

    protected function writeProfile(string $candidate = self::CANDIDATE, string $searchProfiles = self::SEARCH_PROFILES): void
    {
        file_put_contents($this->profileDirectory . '/candidate.yaml', $candidate);
        file_put_contents($this->profileDirectory . '/search_profiles.yaml', $searchProfiles);
    }

    protected function matcher(): JobMatcher
    {
        return self::getContainer()->get(JobMatcher::class);
    }

    protected function persistAnalyzedJob(
        string $title = 'Développeur back-end PHP',
        ?JobRequirements $requirements = null,
        ?\DateTimeImmutable $publishedAt = null,
        PublicationDateConfidence $confidence = PublicationDateConfidence::High,
        string $url = 'https://jobs.example/1',
        string $description = 'Poste de développeur',
    ): Job {
        $job = Job::fromDTO(new JobDto(
            $title,
            $url,
            $description,
            'test',
            $publishedAt,
            publicationDateSource: $publishedAt === null ? PublicationDateSource::Unknown : PublicationDateSource::Api,
            publicationDateConfidence: $publishedAt === null ? PublicationDateConfidence::Unknown : $confidence,
        ));
        $job->recordAnalysis(
            new AiAnalysisDto(['php'], ContractType::Unknown, false, false, 'non précisé', false, Seniority::Unknown),
            50,
            [],
            false,
            $this->now,
            null,
        );
        if ($requirements !== null) {
            $job->setRequirements($requirements);
        }

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    protected function backendRequirements(): JobRequirements
    {
        return new JobRequirements(
            requiredSkills: ['PHP', 'Symfony'],
            niceSkills: ['Docker'],
            seniority: Seniority::Senior,
            minYearsExperience: 4,
            remoteMode: \App\Profile\Model\RemotePreference::Remote,
            contractTypes: [ContractType::Cdi],
        );
    }

    private function useProfileDirectory(string $directory): void
    {
        $_ENV['JOBSCAN_PROFILE_DIR'] = $_SERVER['JOBSCAN_PROFILE_DIR'] = $directory;
        putenv('JOBSCAN_PROFILE_DIR=' . $directory);
    }
}
