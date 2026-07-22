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
 *   4. Déduplication par URL exacte
 *   5. Déduplication par hash de titre normalisé (cross-provider)
 *   6. Pré-score heuristique — les offres sous le seuil n'atteignent pas l'IA
 *   7. Analyse IA (provider compatible OpenAI) + calcul du score final
 *   8. Persistance en base de données
 *   9. Notification Telegram si le score dépasse le seuil de notification
 */
final readonly class JobProcessor
{
    /** Score minimum pour déclencher une notification Telegram. */
    private const int NOTIFICATION_THRESHOLD = 60;

    /** Pré-score heuristique minimum pour appeler l'IA. */
    private const int AI_PRESCORE_THRESHOLD = 10;

    /**
     * @param list<string> $filterKeywords Mots-clés requis dans le titre ou la description (config `app.filter_keywords`)
     * @param int $maxJobAgeDays Âge maximum en jours d'une offre datée (config `app.max_job_age_days`)
     */
    public function __construct(
        private JobRepository $jobRepository,
        private AIClient $AIClient,
        private Scoring $scoringService,
        private Notifier $notificationService,
        private LoggerInterface $logger,
        private array $filterKeywords = [],
        private int $maxJobAgeDays = 30,
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
    public function process(JobDto $dto): void
    {
        $title = strtolower($dto->title);
        $desc = strtolower($dto->description);
        $matches = array_any($this->filterKeywords, fn ($keyword) => str_contains($title, (string) $keyword) || str_contains($desc, (string) $keyword));

        if (!$matches) {
            return;
        }

        if ($dto->url === '') {
            $this->logger->debug('Offre ignorée : URL vide.', ['title' => $dto->title]);

            return;
        }

        if ($dto->publishedAt !== null) {
            $ageDays = (int) $dto->publishedAt->diff(new \DateTimeImmutable())->days;

            if ($ageDays > $this->maxJobAgeDays) {
                $this->logger->debug('Offre trop ancienne ({days}j > {max}j), ignorée.', [
                    'days' => $ageDays,
                    'max' => $this->maxJobAgeDays,
                    'title' => $dto->title,
                ]);

                return;
            }
        }

        if ($this->jobRepository->existsByUrl($dto->url)) {
            $this->logger->debug('Doublon ignoré (URL) : {url}', ['url' => $dto->url]);

            return;
        }

        $titleHash = sha1(Job::normalizeTitle($dto->title));

        if ($this->jobRepository->existsByTitleHash($titleHash)) {
            $this->logger->debug('Doublon ignoré (titre) : {title}', ['title' => $dto->title]);

            return;
        }

        $preScore = $this->scoringService->preScore($dto);

        if ($preScore < self::AI_PRESCORE_THRESHOLD) {
            $this->logger->debug('Pré-score insuffisant ({score}), analyse IA ignorée.', [
                'score' => $preScore,
                'title' => $dto->title,
            ]);

            return;
        }

        $aiData = $this->AIClient->analyze($dto->description);
        ['score' => $score, 'breakdown' => $breakdown] = $this->scoringService->compute($dto, $aiData);

        $job = Job::fromDTO($dto);
        $job->setScore($score);

        $this->jobRepository->save($job);

        $this->logger->info('Job sauvegardé : {title} (score: {score}, source: {source}) — {breakdown}', [
            'title' => $dto->title,
            'score' => $score,
            'source' => $dto->source,
            'breakdown' => implode(', ', $breakdown) ?: 'aucun critère',
        ]);

        if ($score >= self::NOTIFICATION_THRESHOLD) {
            $this->notificationService->notify($job);
        }
    }
}
