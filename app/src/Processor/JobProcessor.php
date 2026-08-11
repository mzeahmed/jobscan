<?php

declare(strict_types=1);

namespace App\Processor;

use App\DTO\JobDto;
use App\Entity\Job;
use App\AI\AIClient;
use App\Scoring\Scoring;
use Psr\Log\LoggerInterface;
use App\Notification\Notifier;
use App\Repository\JobRepository;
use Psr\Cache\InvalidArgumentException;

/**
 * Orchestre le traitement d'une offre d'emploi depuis son ingestion jusqu'à sa persistance.
 *
 * Pipeline appliqué dans l'ordre :
 *   1. Filtre par mots-clés (titre + description)
 *   2. Rejet des offres sans URL
 *   3. Filtre d'ancienneté (si la date de publication est disponible)
 *   4. Déduplication par URL canonique
 *   5. Déduplication par empreinte titre, entreprise et localisation
 *   6. Pré-score heuristique — les offres sous le seuil n'atteignent pas l'IA
 *   7. Analyse IA (provider compatible OpenAI) + calcul du score final
 *   8. Persistance en base de données
 *   9. Notification Telegram si le score dépasse le seuil de notification
 */
final readonly class JobProcessor implements JobProcessorInterface
{
    /**
     * @param list<string> $filterKeywords Mots-clés requis (config `app.profile.filter_keywords`)
     * @param int $maxJobAgeDays Âge maximum (config `app.profile.max_job_age_days`)
     */
    public function __construct(
        private JobRepository $jobRepository,
        private AIClient $AIClient,
        private Scoring $scoringService,
        private Notifier $notificationService,
        private JobIdentity $jobIdentity,
        private LoggerInterface $logger,
        private array $filterKeywords = [],
        private int $maxJobAgeDays = 30,
        private int $aiPrescoreThreshold = 10,
        private int $batchSize = 20,
    ) {
    }

    /**
     * Traite une offre d'emploi et l'enregistre si elle passe tous les filtres.
     *
     * Chaque étape du pipeline peut court-circuiter le traitement : une offre rejetée
     * à n'importe quelle étape est silencieusement ignorée (log debug uniquement).
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
        $pending = [];
        $pendingCanonicalUrls = [];
        $pendingFingerprints = [];
        $aborted = false;

        foreach ($jobDtos as $index => $dto) {
            try {
                $prepared = $this->prepare($dto, $dryRun, $pendingCanonicalUrls, $pendingFingerprints);
                $results[$index] = $prepared['result'];

                if ($prepared['job'] !== null) {
                    $pending[] = [
                        'index' => $index,
                        'job' => $prepared['job'],
                        'used_fallback' => $prepared['result']->usedFallback,
                    ];
                }
            } catch (\Throwable $e) {
                $results[$index] = JobProcessingResult::failed($e->getMessage());
                $this->logger->warning('Échec du traitement de "{title}" : {error}', [
                    'title' => $dto->title,
                    'error' => $e->getMessage(),
                ]);
            }

            if (count($pending) >= max(1, $this->batchSize)
                && !$this->flushPending($pending, $results, $pendingCanonicalUrls, $pendingFingerprints)
            ) {
                $aborted = true;
                for ($remaining = $index + 1, $count = count($jobDtos); $remaining < $count; ++$remaining) {
                    $results[$remaining] = JobProcessingResult::failed('Traitement interrompu après un échec de persistance.');
                }
                break;
            }
        }

        if (!$dryRun && !$aborted && $pending !== []) {
            $this->flushPending($pending, $results, $pendingCanonicalUrls, $pendingFingerprints);
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * @param array<string, true> $pendingCanonicalUrls
     * @param array<string, true> $pendingFingerprints
     * @return array{result: JobProcessingResult, job: ?Job}
     */
    private function prepare(
        JobDto $dto,
        bool $dryRun,
        array &$pendingCanonicalUrls,
        array &$pendingFingerprints,
    ): array {
        $title = strtolower($dto->title);
        $desc = strtolower($dto->description);
        $matches = array_any($this->filterKeywords, fn ($keyword) => str_contains($title, (string) $keyword) || str_contains($desc, (string) $keyword));

        if (!$matches) {
            return ['result' => new JobProcessingResult(JobProcessingStatus::Filtered), 'job' => null];
        }

        if ($dto->url === '') {
            $this->logger->debug('Offre ignorée : URL vide.', ['title' => $dto->title]);

            return ['result' => new JobProcessingResult(JobProcessingStatus::Filtered), 'job' => null];
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

                return ['result' => new JobProcessingResult(JobProcessingStatus::TooOld), 'job' => null];
            }
        }

        $canonicalUrl = $this->jobIdentity->canonicalUrl($dto->url);
        if (isset($pendingCanonicalUrls[$canonicalUrl])
            || $this->jobRepository->existsByUrlOrCanonicalUrl($dto->url, $canonicalUrl)
        ) {
            $this->logger->debug('Doublon ignoré (URL canonique) : {url}', ['url' => $canonicalUrl]);

            return ['result' => new JobProcessingResult(JobProcessingStatus::Duplicate), 'job' => null];
        }

        $fingerprint = $this->jobIdentity->fingerprint($dto);
        if ($fingerprint !== null
            && (isset($pendingFingerprints[$fingerprint]) || $this->jobRepository->existsByFingerprint($fingerprint))
        ) {
            $this->logger->debug('Doublon ignoré (empreinte métier) : {title}', ['title' => $dto->title]);

            return ['result' => new JobProcessingResult(JobProcessingStatus::Duplicate), 'job' => null];
        }

        $preScore = $this->scoringService->preScore($dto);

        if ($preScore < $this->aiPrescoreThreshold) {
            $this->logger->debug('Pré-score insuffisant ({score}), analyse IA ignorée.', [
                'score' => $preScore,
                'title' => $dto->title,
            ]);

            return ['result' => new JobProcessingResult(JobProcessingStatus::LowPrescore), 'job' => null];
        }

        $aiData = $this->AIClient->analyze($dto->description, $dto->publishedAt);
        ['score' => $score, 'breakdown' => $breakdown] = $this->scoringService->compute($dto, $aiData);

        $job = Job::fromDTO($dto);
        $job->setIdentity($canonicalUrl, $fingerprint);
        $job->setScore($score);
        $job->setAnalysis($aiData, $breakdown);

        $pendingCanonicalUrls[$canonicalUrl] = true;
        if ($fingerprint !== null) {
            $pendingFingerprints[$fingerprint] = true;
        }

        if ($dryRun) {
            return [
                'result' => new JobProcessingResult(
                    JobProcessingStatus::DryRun,
                    $score,
                    $this->AIClient->lastAnalysisUsedFallback(),
                ),
                'job' => null,
            ];
        }

        $this->jobRepository->save($job, false);

        return [
            'result' => new JobProcessingResult(
                JobProcessingStatus::Saved,
                $score,
                $this->AIClient->lastAnalysisUsedFallback(),
            ),
            'job' => $job,
        ];
    }

    /**
     * @param list<array{index: int, job: Job, used_fallback: bool}> $pending
     * @param array<int, JobProcessingResult> $results
     * @param array<string, true> $pendingCanonicalUrls
     * @param array<string, true> $pendingFingerprints
     */
    private function flushPending(
        array &$pending,
        array &$results,
        array &$pendingCanonicalUrls,
        array &$pendingFingerprints,
    ): bool {
        try {
            $this->jobRepository->flush();
        } catch (\Throwable $e) {
            foreach ($pending as $entry) {
                $results[$entry['index']] = JobProcessingResult::failed($e->getMessage());
            }

            $this->logger->error('Échec de la persistance d’un lot d’offres.', [
                'batch_size' => count($pending),
                'error' => $e->getMessage(),
            ]);
            $this->resetPending($pending, $pendingCanonicalUrls, $pendingFingerprints);

            return false;
        }

        $hasNotifications = false;
        foreach ($pending as $entry) {
            $job = $entry['job'];
            $notified = $this->notificationService->notify($job);
            $hasNotifications = $hasNotifications || $notified;
            $results[$entry['index']] = new JobProcessingResult(
                JobProcessingStatus::Saved,
                $job->getScore(),
                $entry['used_fallback'],
                $notified,
            );

            $this->logger->info('Job sauvegardé : {title} (score: {score}, source: {source}) — {breakdown}', [
                'title' => $job->getTitle(),
                'score' => $job->getScore(),
                'source' => $job->getSource(),
                'breakdown' => implode(', ', $job->getScoreBreakdown()) ?: 'aucun critère',
            ]);
        }

        if ($hasNotifications) {
            try {
                $this->jobRepository->flush();
            } catch (\Throwable $e) {
                $this->logger->error('Les statuts de notification n’ont pas pu être persistés.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->resetPending($pending, $pendingCanonicalUrls, $pendingFingerprints);

        return true;
    }

    /**
     * @param list<array{index: int, job: Job, used_fallback: bool}> $pending
     * @param array<string, true> $pendingCanonicalUrls
     * @param array<string, true> $pendingFingerprints
     */
    private function resetPending(array &$pending, array &$pendingCanonicalUrls, array &$pendingFingerprints): void
    {
        $this->jobRepository->clear();
        $pending = [];
        $pendingCanonicalUrls = [];
        $pendingFingerprints = [];
    }
}
