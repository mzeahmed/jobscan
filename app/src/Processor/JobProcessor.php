<?php

declare(strict_types=1);

namespace App\Processor;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Scoring\Scoring;
use App\Entity\JobSource;
use Psr\Log\LoggerInterface;
use App\Analysis\JobAnalyzer;
use App\Repository\JobRepository;
use App\Repository\JobSourceRepository;
use Psr\Cache\InvalidArgumentException;

/**
 * Orchestre l'ingestion d'une offre d'emploi : filtres, déduplication et persistance.
 *
 * L'analyse IA et la notification ne font **pas** partie de ce service : une offre est
 * persistée (statut d'analyse `pending`) avant toute analyse, afin que la collecte ne
 * dépende jamais de la disponibilité du LLM. Les offres en attente sont analysées par
 * `PendingAnalysisRunner`. Seul le mode dry-run analyse en mémoire, pour prévisualiser
 * les scores sans rien écrire.
 *
 * Pipeline appliqué dans l'ordre :
 *   1. Exclusion métier de plateforme
 *   2. Filtre par mots-clés (titre + description)
 *   3. Rejet des offres sans URL
 *   4. Filtre d'ancienneté (si la date de publication est disponible)
 *   5. Doublon **certain** (URL, URL canonique ou identifiant externe) : l'offre existante
 *      est mise à jour (dernière observation, date de publication la plus fiable, source)
 *   6. Pré-score heuristique — les offres sous le seuil ne sont pas conservées
 *   7. Doublon **probable** (même empreinte titre/entreprise/lieu) : l'offre est conservée
 *      et simplement signalée, jamais fusionnée
 *   8. Persistance (offre + source)
 */
final readonly class JobProcessor implements JobProcessorInterface
{
    /**
     * @param list<string> $filterKeywords Mots-clés requis (config `app.profile.filter_keywords`)
     * @param list<string> $excludedPlatformDomains Exclusions métier (config `app.profile.excluded_platform_domains`)
     * @param int $maxJobAgeDays Âge maximum (config `app.profile.max_job_age_days`)
     */
    public function __construct(
        private JobRepository $jobRepository,
        private JobSourceRepository $jobSourceRepository,
        private Scoring $scoringService,
        private JobAnalyzer $analyzer,
        private JobIdentity $jobIdentity,
        private LoggerInterface $logger,
        private array $filterKeywords = [],
        private array $excludedPlatformDomains = [],
        private int $maxJobAgeDays = 30,
        private int $aiPrescoreThreshold = 10,
        private int $batchSize = 20,
    ) {
    }

    /**
     * Traite une offre d'emploi et l'enregistre si elle passe tous les filtres.
     *
     * @throws InvalidArgumentException si le cache IA est inaccessible
     */
    public function process(JobDto $dto, bool $dryRun = false): JobProcessingResult
    {
        return $this->processBatch([$dto], $dryRun)[0];
    }

    /**
     * @param iterable<JobDto> $jobs
     * @return list<JobProcessingResult>
     */
    public function processBatch(iterable $jobs, bool $dryRun = false): array
    {
        $jobDtos = is_array($jobs) ? array_values($jobs) : array_values(iterator_to_array($jobs));
        $results = [];
        $pendingCanonicalUrls = [];
        $savedIndexes = [];
        $pendingWrites = 0;
        $aborted = false;

        foreach ($jobDtos as $index => $dto) {
            try {
                $prepared = $this->prepare($dto, $dryRun, $pendingCanonicalUrls);
                $results[$index] = $prepared['result'];

                if ($prepared['persisted']) {
                    $savedIndexes[] = $index;
                }
                if ($prepared['persisted'] || $prepared['touched']) {
                    ++$pendingWrites;
                }
            } catch (\Throwable $e) {
                $results[$index] = JobProcessingResult::failed($e->getMessage());
                $this->logger->warning('Échec du traitement de "{title}" : {error}', [
                    'title' => $dto->title,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($pendingWrites < max(1, $this->batchSize)) {
                continue;
            }

            if (!$this->flushBatch($pendingCanonicalUrls)) {
                $aborted = true;
                $this->markAsFailed($results, $savedIndexes, 'Persistance du lot en échec.');
                for ($remaining = $index + 1, $count = count($jobDtos); $remaining < $count; ++$remaining) {
                    $results[$remaining] = JobProcessingResult::failed('Traitement interrompu après un échec de persistance.');
                }
                break;
            }

            $savedIndexes = [];
            $pendingWrites = 0;
        }

        if (!$dryRun && !$aborted && $pendingWrites > 0 && !$this->flushBatch($pendingCanonicalUrls)) {
            $this->markAsFailed($results, $savedIndexes, 'Persistance du lot en échec.');
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * @param array<string, true> $pendingCanonicalUrls
     *
     * @return array{result: JobProcessingResult, persisted: bool, touched: bool}
     */
    private function prepare(JobDto $dto, bool $dryRun, array &$pendingCanonicalUrls): array
    {
        $rejected = static fn (JobProcessingStatus $status): array => [
            'result' => new JobProcessingResult($status),
            'persisted' => false,
            'touched' => false,
        ];

        if (DomainFilter::matches($dto->url, $this->excludedPlatformDomains)) {
            $this->logger->debug('Offre ignorée : plateforme exclue.', [
                'url' => $dto->url,
                'title' => $dto->title,
            ]);

            return $rejected(JobProcessingStatus::Filtered);
        }

        $title = strtolower($dto->title);
        $desc = strtolower($dto->description);
        $matches = array_any($this->filterKeywords, fn ($keyword) => str_contains($title, (string) $keyword) || str_contains($desc, (string) $keyword));

        if (!$matches) {
            return $rejected(JobProcessingStatus::Filtered);
        }

        if ($dto->url === '') {
            $this->logger->debug('Offre ignorée : URL vide.', ['title' => $dto->title]);

            return $rejected(JobProcessingStatus::Filtered);
        }

        $now = new \DateTimeImmutable();
        if ($dto->publishedAt !== null && $dto->publishedAt <= $now) {
            $ageDays = (int) $dto->publishedAt->diff($now)->days;

            if ($ageDays > $this->maxJobAgeDays) {
                $this->logger->debug('Offre trop ancienne ({days}j > {max}j), ignorée.', [
                    'days' => $ageDays,
                    'max' => $this->maxJobAgeDays,
                    'title' => $dto->title,
                ]);

                return $rejected(JobProcessingStatus::TooOld);
            }
        }

        $canonicalUrl = $this->jobIdentity->canonicalUrl($dto->url);
        if (isset($pendingCanonicalUrls[$canonicalUrl])) {
            $this->logger->debug('Doublon ignoré (URL canonique, même lot) : {url}', ['url' => $canonicalUrl]);

            return $rejected(JobProcessingStatus::Duplicate);
        }

        $existing = $this->jobRepository->findCertainDuplicate($dto->url, $canonicalUrl, $dto->source, $dto->externalId);
        if ($existing !== null) {
            $this->logger->debug('Doublon certain : {url}', ['url' => $canonicalUrl]);

            if ($dryRun) {
                return $rejected(JobProcessingStatus::Duplicate);
            }

            $this->registerSighting($existing, $dto, $canonicalUrl, $now);
            $pendingCanonicalUrls[$canonicalUrl] = true;

            return [
                'result' => new JobProcessingResult(JobProcessingStatus::Duplicate),
                'persisted' => false,
                'touched' => true,
            ];
        }

        if ($this->scoringService->preScore($dto) < $this->aiPrescoreThreshold) {
            $this->logger->debug('Pré-score insuffisant, offre non conservée.', ['title' => $dto->title]);

            return $rejected(JobProcessingStatus::LowPrescore);
        }

        $fingerprint = $this->jobIdentity->fingerprint($dto);
        $job = Job::fromDTO($dto);
        $job->setIdentity($canonicalUrl, $fingerprint);

        $probableDuplicateId = $fingerprint === null ? null : $this->jobRepository->findFirstIdByFingerprint($fingerprint);
        if ($probableDuplicateId !== null) {
            $job->markProbableDuplicateOf($probableDuplicateId);
            $this->logger->info('Doublon probable (même titre, entreprise et lieu) conservé : {title}', [
                'title' => $dto->title,
                'duplicate_of' => $probableDuplicateId,
            ]);
        }

        $pendingCanonicalUrls[$canonicalUrl] = true;

        if ($dryRun) {
            $outcome = $this->analyzer->analyze($job, $now);

            return [
                'result' => $outcome->succeeded
                    ? new JobProcessingResult(JobProcessingStatus::DryRun, $outcome->score, $outcome->usedFallback)
                    : JobProcessingResult::failed((string) $outcome->error),
                'persisted' => false,
                'touched' => false,
            ];
        }

        $this->jobRepository->save($job, false);
        $this->jobSourceRepository->save(new JobSource($job, $dto, $canonicalUrl, $now));

        return [
            'result' => new JobProcessingResult(JobProcessingStatus::Saved),
            'persisted' => true,
            'touched' => false,
        ];
    }

    /**
     * Enregistre une nouvelle observation d'une offre déjà connue : dernière vue, date
     * de publication la plus fiable, et source si cette URL ou ce provider est nouveau.
     */
    private function registerSighting(Job $existing, JobDto $dto, string $canonicalUrl, \DateTimeImmutable $now): void
    {
        $existing->registerSighting($dto, $now);

        $source = $this->jobSourceRepository->findByIdentity($canonicalUrl, $dto->source, $dto->externalId);
        if ($source === null) {
            $this->jobSourceRepository->save(new JobSource($existing, $dto, $canonicalUrl, $now));

            return;
        }

        $source->registerSighting($dto, $now);
    }

    /**
     * @param array<string, true> $pendingCanonicalUrls
     */
    private function flushBatch(array &$pendingCanonicalUrls): bool
    {
        try {
            $this->jobRepository->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Échec de la persistance d’un lot d’offres.', ['error' => $e->getMessage()]);
            $this->jobRepository->clear();
            $pendingCanonicalUrls = [];

            return false;
        }

        $this->jobRepository->clear();
        $pendingCanonicalUrls = [];

        return true;
    }

    /**
     * @param array<int, JobProcessingResult> $results
     * @param list<int> $indexes
     */
    private function markAsFailed(array &$results, array $indexes, string $error): void
    {
        foreach ($indexes as $index) {
            $results[$index] = JobProcessingResult::failed($error);
        }
    }
}
