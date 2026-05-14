<?php

declare(strict_types=1);

namespace App\Service\Processor;

use App\DTO\JobDTO;
use App\Entity\Job;
use App\Service\AI\AIClient;
use Psr\Log\LoggerInterface;
use App\Repository\JobRepository;
use App\Service\Scoring\ScoringService;
use Psr\Cache\InvalidArgumentException;
use App\Service\Notification\NotificationService;

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
 *   7. Analyse IA (LM Studio) + calcul du score final
 *   8. Persistance en base de données
 *   9. Notification Telegram si le score dépasse le seuil de notification
 */
final class JobProcessor
{
    /** Score minimum pour déclencher une notification Telegram. */
    private const NOTIFICATION_THRESHOLD = 60;

    /** Pré-score heuristique minimum pour appeler l'IA. */
    private const AI_PRESCORE_THRESHOLD = 15;

    /**
     * @param list<string> $filterKeywords Mots-clés requis dans le titre ou la description (config `app.filter_keywords`)
     * @param int          $maxJobAgeDays  Âge maximum en jours d'une offre datée (config `app.max_job_age_days`)
     */
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly AIClient $AIClient,
        private readonly ScoringService $scoringService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
        private readonly array $filterKeywords = [],
        private readonly int $maxJobAgeDays = 30,
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
    public function process(JobDTO $dto): void
    {
        $title = strtolower($dto->title);
        $desc = strtolower($dto->description);

        $matches = false;
        foreach ($this->filterKeywords as $keyword) {
            if (str_contains($title, $keyword) || str_contains($desc, $keyword)) {
                $matches = true;
                break;
            }
        }

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
