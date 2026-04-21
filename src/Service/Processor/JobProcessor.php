<?php

declare(strict_types=1);

namespace App\Service\Processor;

use App\DTO\JobDTO;
use App\Entity\Job;
use Psr\Log\LoggerInterface;
use App\Repository\JobRepository;
use App\Service\AI\PerplexityClient;
use App\Service\Scoring\ScoringService;
use App\Service\Notification\NotificationService;

final class JobProcessor
{
    // Score minimum pour déclencher une notification
    private const NOTIFICATION_THRESHOLD = 70;

    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly PerplexityClient $perplexityClient,
        private readonly ScoringService $scoringService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Traite une offre : déduplication → analyse IA → scoring → persistance → notification.
     */
    public function process(JobDTO $dto): void
    {
        if (! str_contains(strtolower($dto->title), 'php')) {
            return;
        }

        if ($dto->url === '') {
            $this->logger->debug('Offre ignorée : URL vide.', ['title' => $dto->title]);

            return;
        }

        if ($this->jobRepository->existsByUrl($dto->url)) {
            $this->logger->debug('Doublon ignoré : {url}', ['url' => $dto->url]);

            return;
        }

        $aiData = $this->perplexityClient->analyze($dto->description);
        $score = $this->scoringService->compute($dto, $aiData);

        $job = Job::fromDTO($dto);
        $job->setScore($score);

        $this->jobRepository->save($job, false);

        $this->logger->info('Job sauvegardé : {title} (score: {score}, source: {source})', [
            'title' => $dto->title,
            'score' => $score,
            'source' => $dto->source,
        ]);

        if ($score >= self::NOTIFICATION_THRESHOLD) {
            $this->notificationService->notify($job);
        }
    }
}
