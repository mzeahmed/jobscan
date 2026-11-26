<?php

declare(strict_types=1);

namespace App\Command;

use App\Analytics\Rate;
use App\Analytics\Window;
use App\Analytics\BreakdownRow;
use App\Analytics\AnalyticsReport;
use App\Analytics\AnalyticsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:analytics:report',
    description: 'Ce qui fonctionne dans votre recherche : parcours des candidatures, réponses par source, CV, profil et canal, rendement des sources.',
)]
final class AnalyticsReportCommand extends Command
{
    private const array TITLES = ['source' => 'Par source de l\'offre', 'cv' => 'Par version de CV', 'profile' => 'Par profil de recherche', 'channel' => 'Par canal d\'envoi'];

    public function __construct(private readonly AnalyticsService $analytics)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Fenêtre : 30d, 12w ou all (tout l\'historique).', '90d')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table ou json.', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $since = Window::since((string) $input->getOption('since'), $now);
        if ($since === false) {
            $io->error('--since : 30d, 12w ou all.');

            return Command::INVALID;
        }

        $format = (string) $input->getOption('format');
        if (!in_array($format, ['table', 'json'], true)) {
            $io->error('--format : table ou json.');

            return Command::INVALID;
        }

        $report = $this->analytics->report($since, $now);

        if ($format === 'json') {
            $output->writeln(json_encode($this->toArray($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $this->render($io, $report);

        return Command::SUCCESS;
    }

    private function render(SymfonyStyle $io, AnalyticsReport $report): void
    {
        $io->title('JOBSCAN — Analytics');
        $io->writeln($report->since === null ? 'Période : tout l\'historique.' : sprintf('Période : depuis le %s.', $report->since->format('d/m/Y')));

        $f = $report->funnel;
        $io->section('Parcours des candidatures envoyées');
        if ($f->sent === 0) {
            $io->note('Aucune candidature envoyée sur la période. Consignez-les avec app:applications:status.');
        } else {
            $io->definitionList(
                ['Envoyées' => $f->sent],
                ['Réponses (entretien, test, proposition ou refus)' => Rate::format($f->responded, $f->sent)],
                ['Ont atteint un entretien ou un test' => Rate::format($f->interviews, $f->sent)],
                ['Ont atteint une proposition' => Rate::format($f->offers, $f->sent)],
                ['Acceptées' => $f->accepted],
                ['Refusées (statut actuel)' => $f->rejected],
                ['Sans réponse (statut actuel)' => $f->noResponse],
                ['Délai médian de première réponse' => $f->medianDaysToResponse === null ? '—' : sprintf('%.1f jour(s)', $f->medianDaysToResponse)],
            );
        }

        foreach (self::TITLES as $dimension => $title) {
            $rows = $report->breakdowns[$dimension] ?? [];
            if ($rows === []) {
                continue;
            }

            $io->section($title);
            $io->table(['', 'Envoyées', 'Réponses', 'Entretiens', 'Propositions'], array_map(static fn (BreakdownRow $r): array => [
                $r->label,
                $r->sent,
                Rate::format($r->responded, $r->sent),
                Rate::format($r->interviews, $r->sent),
                Rate::format($r->offers, $r->sent),
            ], $rows));
        }

        $io->section('Rendement des sources (offres collectées, hors doublons)');
        if ($report->sourcing === []) {
            $io->note('Aucune offre collectée sur la période.');
        } else {
            $io->table(['Source', 'Collectées', 'Compatibles', 'Notifiées', 'Suivies', 'Postulées'], array_map(static fn ($s): array => [
                $s->source,
                $s->collected,
                Rate::format($s->compatible, $s->collected),
                $s->notified,
                $s->tracked,
                $s->applied,
            ], $report->sourcing));
        }

        $io->writeln(sprintf('<comment>Lecture :</comment> ce sont des comptages sur vos données, pas des preuves. Un pourcentage n\'apparaît qu\'à partir de %d candidatures ; les offres visées diffèrent d\'un CV, d\'un canal ou d\'une source à l\'autre.', Rate::MIN_SAMPLE));
    }

    /** @return array<string, mixed> */
    private function toArray(AnalyticsReport $report): array
    {
        $rate = static fn (int $part, int $total): ?float => ($r = Rate::of($part, $total)) === null ? null : round($r, 4);
        $f = $report->funnel;

        return [
            'since' => $report->since?->format(\DATE_ATOM),
            'generated_at' => $report->generatedAt->format(\DATE_ATOM),
            'min_sample_for_rates' => Rate::MIN_SAMPLE,
            'funnel' => [
                'sent' => $f->sent,
                'responded' => $f->responded,
                'response_rate' => $rate($f->responded, $f->sent),
                'interviews' => $f->interviews,
                'offers' => $f->offers,
                'accepted' => $f->accepted,
                'rejected' => $f->rejected,
                'no_response' => $f->noResponse,
                'median_days_to_response' => $f->medianDaysToResponse === null ? null : round($f->medianDaysToResponse, 2),
            ],
            'breakdowns' => array_map(static fn (array $rows): array => array_map(static fn (BreakdownRow $r): array => [
                'label' => $r->label,
                'sent' => $r->sent,
                'responded' => $r->responded,
                'response_rate' => $rate($r->responded, $r->sent),
                'interviews' => $r->interviews,
                'offers' => $r->offers,
            ], $rows), $report->breakdowns),
            'sourcing' => array_map(static fn ($s): array => [
                'source' => $s->source,
                'collected' => $s->collected,
                'compatible' => $s->compatible,
                'notified' => $s->notified,
                'tracked' => $s->tracked,
                'applied' => $s->applied,
            ], $report->sourcing),
        ];
    }
}
