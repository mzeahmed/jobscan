<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Entity\Job;
use Psr\Log\LoggerInterface;
use App\Repository\JobRepository;
use App\Notification\TelegramHtml;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;
use App\Notification\TelegramNotifier;
use App\Repository\JobMatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\ApplicationRepository;
use App\Tracking\InvalidApplicationException;
use App\Notification\TelegramMessageFormatter;

/**
 * Traite une mise à jour Telegram : appui sur un bouton d'offre, ou commande (`/offres`, `/candidatures`, `/aide`).
 *
 * Toute mise à jour qui ne vient pas du chat autorisé est ignorée sans réponse. Les actions ne font que consigner
 * ce que l'utilisateur déclare : Jobscan n'envoie jamais rien à un recruteur.
 */
final readonly class UpdateHandler
{
    private const int OFFERS_LIMIT = 5;
    private const int APPLICATIONS_LIMIT = 10;

    public function __construct(
        private UpdateAuthorizer $authorizer,
        private TelegramBot $bot,
        private TelegramNotifier $notifier,
        private TelegramMessageFormatter $formatter,
        private JobRepository $jobs,
        private JobMatchRepository $matches,
        private ApplicationRepository $applications,
        private ApplicationManager $manager,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update, \DateTimeImmutable $now): void
    {
        if (is_array($update['callback_query'] ?? null)) {
            $this->handleCallback($update['callback_query'], $now);
        } elseif (is_array($update['message'] ?? null)) {
            $this->handleMessage($update['message'], $now);
        }
    }

    /** @param array<string, mixed> $callback */
    private function handleCallback(array $callback, \DateTimeImmutable $now): void
    {
        $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        $chatId = self::int($message['chat']['id'] ?? null);
        $userId = self::int($callback['from']['id'] ?? null);
        $callbackId = is_string($callback['id'] ?? null) ? $callback['id'] : null;

        if ($chatId === null || $userId === null || $callbackId === null || !$this->authorizer->isAuthorized($chatId, $userId)) {
            $this->logger->warning('Action Telegram refusée : expéditeur non autorisé.', ['chat' => $chatId, 'user' => $userId]);

            return;
        }

        $parsed = JobKeyboard::parse(is_string($callback['data'] ?? null) ? $callback['data'] : '');
        $job = $parsed === null ? null : $this->jobs->find($parsed[1]);
        if ($parsed === null || $job === null) {
            $this->bot->answerCallback($callbackId, 'Offre introuvable.');

            return;
        }

        [$action] = $parsed;
        [$text, $markup] = $this->apply($action, $job, $now);
        $this->entityManager->flush();

        $this->bot->answerCallback($callbackId, $text);
        $messageId = self::int($message['message_id'] ?? null);
        if ($markup !== null && $messageId !== null) {
            $this->bot->editKeyboard($chatId, $messageId, $markup);
        }
    }

    /** @return array{0: string, 1: array<string, mixed>|null} Texte de confirmation et nouveau clavier */
    private function apply(string $action, Job $job, \DateTimeImmutable $now): array
    {
        switch ($action) {
            case JobKeyboard::TRACK:
                $application = $this->applications->findOneByJob($job) ?? $this->manager->start($job, $now);

                return [
                    'Candidature ouverte (brouillon) : rien n\'a été envoyé.',
                    JobKeyboard::forJob($job, true, $application->getStatus() === ApplicationStatus::Draft),
                ];
            case JobKeyboard::APPLIED:
                $application = $this->applications->findOneByJob($job);
                if ($application === null) {
                    return ['Aucune candidature pour cette offre.', null];
                }
                try {
                    if ($application->getStatus() === ApplicationStatus::Draft) {
                        $this->manager->changeStatus($application, ApplicationStatus::Applied, $now, 'Via Telegram');
                    }
                } catch (InvalidApplicationException $e) {
                    return [$e->getMessage(), null];
                }

                return ['Candidature consignée comme envoyée.', JobKeyboard::forJob($job, true, false)];
            case JobKeyboard::RESET:
                $this->manager->decide($job, null, $now);

                return ['Décision annulée.', JobKeyboard::forJob($job)];
            default:
                $state = JobKeyboard::stateFor($action);
                $this->manager->decide($job, $state, $now);

                return [$state === null ? 'Action inconnue.' : sprintf('Offre : %s.', mb_strtolower($state->label())), JobKeyboard::forJob($job)];
        }
    }

    /** @param array<string, mixed> $message */
    private function handleMessage(array $message, \DateTimeImmutable $now): void
    {
        $chatId = self::int($message['chat']['id'] ?? null);
        $userId = self::int($message['from']['id'] ?? null);

        if ($chatId === null || $userId === null || !$this->authorizer->isAuthorized($chatId, $userId)) {
            $this->logger->warning('Message Telegram ignoré : expéditeur non autorisé.', ['chat' => $chatId, 'user' => $userId]);

            return;
        }

        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';
        $command = strtolower((string) preg_replace('/@\S+$/', '', explode(' ', $text)[0]));

        match ($command) {
            '/offres' => $this->sendOffers($now),
            '/candidatures' => $this->sendApplications(),
            default => $this->notifier->send($this->help(), true, false),
        };
    }

    private function sendOffers(\DateTimeImmutable $now): void
    {
        $top = $this->matches->findTop(self::OFFERS_LIMIT, null, true, true);
        if ($top === []) {
            $this->notifier->send('Aucune offre compatible à trier pour le moment.', true, false);

            return;
        }

        foreach ($top as $match) {
            $this->notifier->send($this->formatter->card($match, $now), true, false, JobKeyboard::forJob($match->getJob()));
        }
    }

    private function sendApplications(): void
    {
        $counts = $this->applications->countByStatus();
        if ($counts === []) {
            $this->notifier->send('Aucune candidature suivie. Utilisez « Suivre » sur une offre.', true, false);

            return;
        }

        $lines = ['<b>Candidatures</b>'];
        foreach (ApplicationStatus::cases() as $status) {
            if (($counts[$status->value] ?? 0) > 0) {
                $lines[] = sprintf('%s : %d', TelegramHtml::escape($status->label()), $counts[$status->value]);
            }
        }

        $open = array_filter($this->applications->findByStatuses(null, 100), static fn ($application): bool => !$application->getStatus()->isTerminal());
        $open = array_slice(array_values($open), 0, self::APPLICATIONS_LIMIT);
        if ($open !== []) {
            $lines[] = '';
            $lines[] = '<b>En cours</b>';
            foreach ($open as $application) {
                $job = $application->getJob();
                $lines[] = sprintf(
                    '• %s — %s%s',
                    TelegramHtml::escape($application->getStatus()->label()),
                    TelegramHtml::escape(mb_substr((string) $job->getTitle(), 0, 80)),
                    $job->getCompany() === null ? '' : ' (' . TelegramHtml::escape(mb_substr($job->getCompany(), 0, 40)) . ')',
                );
            }
        }

        $this->notifier->send(implode("\n", $lines), true, false);
    }

    private function help(): string
    {
        return implode("\n", [
            '<b>Jobscan</b>',
            '/offres — les meilleures offres à trier (avec boutons)',
            '/candidatures — vos candidatures en cours',
            '/aide — ce message',
            '',
            'Jobscan ne contacte jamais un recruteur : il consigne ce que vous faites.',
        ]);
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
