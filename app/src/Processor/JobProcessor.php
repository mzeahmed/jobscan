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
        $title = strtolower($dto->title);
        $desc = strtolower($dto->description);
        $matches = array_any($this->filterKeywords, fn ($keyword) => str_contains($title, (string) $keyword) || str_contains($desc, (string) $keyword));

        if (!$matches) {
            return new JobProcessingResult(JobProcessingStatus::Filtered);
        }

        if ($dto->url === '') {
            $this->logger->debug('Offre ignorée : URL vide.', ['title' => $dto->title]);

            return new JobProcessingResult(JobProcessingStatus::Filtered);
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

                return new JobProcessingResult(JobProcessingStatus::TooOld);
            }
        }

        $canonicalUrl = $this->jobIdentity->canonicalUrl($dto->url);
        if ($this->jobRepository->existsByUrlOrCanonicalUrl($dto->url, $canonicalUrl)) {
            $this->logger->debug('Doublon ignoré (URL canonique) : {url}', ['url' => $canonicalUrl]);

            return new JobProcessingResult(JobProcessingStatus::Duplicate);
        }

        $fingerprint = $this->jobIdentity->fingerprint($dto);
        if ($fingerprint !== null && $this->jobRepository->existsByFingerprint($fingerprint)) {
            $this->logger->debug('Doublon ignoré (empreinte métier) : {title}', ['title' => $dto->title]);

            return new JobProcessingResult(JobProcessingStatus::Duplicate);
        }

        $preScore = $this->scoringService->preScore($dto);

        if ($preScore < $this->aiPrescoreThreshold) {
            $this->logger->debug('Pré-score insuffisant ({score}), analyse IA ignorée.', [
                'score' => $preScore,
                'title' => $dto->title,
            ]);

            return new JobProcessingResult(JobProcessingStatus::LowPrescore);
        }

        $aiData = $this->AIClient->analyze($dto->description, $dto->publishedAt);
        ['score' => $score, 'breakdown' => $breakdown] = $this->scoringService->compute($dto, $aiData);

        $job = Job::fromDTO($dto);
        $job->setIdentity($canonicalUrl, $fingerprint);
        $job->setScore($score);
        $job->setAnalysis($aiData, $breakdown);

        if ($dryRun) {
            return new JobProcessingResult(
                JobProcessingStatus::DryRun,
                $score,
                $this->AIClient->lastAnalysisUsedFallback(),
            );
        }

        $this->jobRepository->save($job);

        $this->logger->info('Job sauvegardé : {title} (score: {score}, source: {source}) — {breakdown}', [
            'title' => $dto->title,
            'score' => $score,
            'source' => $dto->source,
            'breakdown' => implode(', ', $breakdown) ?: 'aucun critère',
        ]);

        $this->notificationService->notify($job);

        return new JobProcessingResult(
            JobProcessingStatus::Saved,
            $score,
            $this->AIClient->lastAnalysisUsedFallback(),
            $job->getNotifiedAt() !== null,
        );
    }
}
