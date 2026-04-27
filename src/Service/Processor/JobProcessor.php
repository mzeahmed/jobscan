<?php

declare(strict_types=1);

namespace App\Service\Processor;

use App\DTO\JobDTO;
use App\Entity\Job;
use App\Service\AI\AIClient;
use Psr\Log\LoggerInterface;
use App\Repository\JobRepository;
use App\Service\Scoring\ScoringService;
use App\Service\Notification\NotificationService;

final class JobProcessor
{
    private const NOTIFICATION_THRESHOLD = 60;
    private const AI_PRESCORE_THRESHOLD = 15;

    /**
     * @param list<string> $filterKeywords
     */
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly AIClient $AIClient,
        private readonly ScoringService $scoringService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
        private readonly array $filterKeywords = [],
    ) {
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
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

        if ($this->jobRepository->existsByUrl($dto->url)) {
            $this->logger->debug('Doublon ignoré : {url}', ['url' => $dto->url]);

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
