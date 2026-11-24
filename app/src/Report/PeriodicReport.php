<?php

declare(strict_types=1);

namespace App\Report;

use App\Health\HealthStatus;
use App\Health\HealthService;
use Doctrine\DBAL\Connection;
use App\Notification\TelegramHtml;
use App\Repository\JobMatchRepository;
use App\Repository\ApplicationRepository;

/**
 * Rapport de période (par défaut 7 jours) : collecte, offres à trier, candidatures, relances dues, santé de l'installation.
 *
 * Le contenu est constitué de décomptes et d'intitulés d'annonces publiques : aucune donnée de recruteur ni de profil, donc
 * sans risque à envoyer par Telegram.
 */
final readonly class PeriodicReport
{
    private const int TO_SORT = 5;
    private const int TELEGRAM_LIMIT = 3800;

    public function __construct(
        private Connection $connection,
        private JobMatchRepository $matches,
        private ApplicationRepository $applications,
        private HealthService $health,
    ) {
    }

    public function build(\DateTimeImmutable $now, int $days): ReportData
    {
        $from = $now->modify(sprintf('-%d days', $days));
        $window = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $now->format('Y-m-d H:i:s')];

        $count = fn (string $sql): int => (int) $this->connection->fetchOne($sql, $window);

        $toSort = [];
        foreach ($this->matches->findTop(self::TO_SORT, null, true, true) as $match) {
            $job = $match->getJob();
            $toSort[] = ['title' => (string) $job->getTitle(), 'company' => $job->getCompany(), 'url' => (string) $job->getUrl(), 'priority' => $match->getPriorityScore()];
        }

        $health = $this->health->run($now);

        return new ReportData(
            $from,
            $now,
            $count('SELECT COUNT(*) FROM job WHERE probable_duplicate_of_id IS NULL AND first_seen_at >= :from AND first_seen_at <= :to'),
            $count("SELECT COUNT(*) FROM job j WHERE j.probable_duplicate_of_id IS NULL AND j.first_seen_at >= :from AND j.first_seen_at <= :to AND EXISTS (SELECT 1 FROM job_match m WHERE m.job_id = j.id AND m.verdict = 'eligible')"),
            $count('SELECT COUNT(*) FROM job j WHERE (j.notified_at >= :from AND j.notified_at <= :to) OR EXISTS (SELECT 1 FROM notification_log n WHERE n.job_id = j.id AND n.sent_at >= :from AND n.sent_at <= :to)'),
            $toSort,
            $count('SELECT COUNT(*) FROM job_application WHERE applied_at >= :from AND applied_at <= :to'),
            $count('SELECT COUNT(*) FROM job_application WHERE responded_at >= :from AND responded_at <= :to'),
            $count("SELECT COUNT(DISTINCT application_id) FROM job_application_status_change WHERE to_status IN ('interview', 'technical_test') AND changed_at >= :from AND changed_at <= :to"),
            $count("SELECT COUNT(DISTINCT application_id) FROM job_application_status_change WHERE to_status IN ('offer', 'accepted') AND changed_at >= :from AND changed_at <= :to"),
            $this->applications->countDueFollowUps($now),
            count($health->checks),
            $health->problems(),
        );
    }

    public function markdown(ReportData $report): string
    {
        $lines = [
            sprintf('# Rapport Jobscan — %d derniers jours', $report->days()),
            sprintf('_Du %s au %s_', $report->from->format('d/m/Y'), $report->to->format('d/m/Y')),
            '',
            '## Collecte',
            sprintf('- Offres collectées : **%d** (hors doublons)', $report->collected),
            sprintf('- Compatibles avec un profil de recherche : **%d**', $report->compatible),
            sprintf('- Notifiées : **%d**', $report->notified),
            '',
            '## Candidatures',
            sprintf('- Envoyées : **%d**', $report->applicationsSent),
            sprintf('- Réponses reçues : **%d**', $report->responses),
            sprintf('- Entretiens ou tests atteints : **%d**', $report->interviews),
            sprintf('- Propositions : **%d**', $report->offers),
            sprintf('- Relances à faire : **%d**', $report->dueFollowUps),
            '',
            '## À trier',
        ];

        if ($report->toSort === []) {
            $lines[] = 'Aucune offre compatible en attente.';
        }
        foreach ($report->toSort as $offer) {
            $lines[] = sprintf('- [%s](%s)%s — priorité %d', $offer['title'], $offer['url'], $offer['company'] === null ? '' : ' (' . $offer['company'] . ')', $offer['priority']);
        }

        $lines[] = '';
        $lines[] = '## Santé';
        if ($report->problems === []) {
            $lines[] = sprintf('Tout est en ordre (%d contrôles).', $report->healthChecks);
        }
        foreach ($report->problems as $problem) {
            $lines[] = sprintf('- %s **%s** : %s', $problem->status->symbol(), $problem->name, $problem->message);
        }

        return implode("\n", $lines) . "\n";
    }

    public function telegram(ReportData $report): string
    {
        $lines = [
            sprintf('📊 <b>Rapport Jobscan — %d jours</b>', $report->days()),
            sprintf('Collectées %d · compatibles %d · notifiées %d', $report->collected, $report->compatible, $report->notified),
            sprintf('Candidatures : %d envoyée(s) · %d réponse(s) · %d entretien(s) · %d proposition(s)', $report->applicationsSent, $report->responses, $report->interviews, $report->offers),
        ];
        if ($report->dueFollowUps > 0) {
            $lines[] = sprintf('⏰ %d relance(s) à faire', $report->dueFollowUps);
        }

        if ($report->toSort !== []) {
            $lines[] = '';
            $lines[] = '<b>À trier</b>';
            foreach ($report->toSort as $offer) {
                $lines[] = sprintf(
                    '• %s%s — %d',
                    TelegramHtml::link($offer['url'], mb_substr($offer['title'], 0, 90)),
                    $offer['company'] === null ? '' : ' (' . TelegramHtml::escape(mb_substr($offer['company'], 0, 40)) . ')',
                    $offer['priority'],
                );
            }
        }

        $lines[] = '';
        if ($report->problems === []) {
            $lines[] = '✅ Santé : tout est en ordre';
        } else {
            $lines[] = '<b>Santé</b>';
            foreach ($report->problems as $problem) {
                $lines[] = sprintf('%s %s : %s', $problem->status->symbol(), TelegramHtml::escape($problem->name), TelegramHtml::escape(mb_substr($problem->message, 0, 160)));
            }
        }

        $text = implode("\n", $lines);

        return mb_strlen($text) > self::TELEGRAM_LIMIT ? mb_substr($text, 0, self::TELEGRAM_LIMIT - 1) . '…' : $text;
    }

    public function worstStatus(ReportData $report): HealthStatus
    {
        return HealthStatus::worst(...array_map(static fn ($problem): HealthStatus => $problem->status, $report->problems));
    }
}
