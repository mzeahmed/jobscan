<?php

declare(strict_types=1);

namespace App\Command;

use App\Health\HealthStatus;
use App\Health\HealthService;
use App\Health\HealthStateStore;
use App\Notification\TelegramHtml;
use App\Notification\TelegramNotifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:health:check',
    description: 'Contrôle la santé de l\'installation (base, migrations, sources, analyses, notifications, rappels, disque, profils).',
)]
final class HealthCheckCommand extends Command
{
    public function __construct(
        private readonly HealthService $health,
        private readonly HealthStateStore $state,
        private readonly TelegramNotifier $telegram,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table ou json.', 'table')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Code de sortie non nul dès un avertissement (par défaut : seulement pour un état critique).')
            ->addOption('alert', null, InputOption::VALUE_NONE, 'Prévient par Telegram quand un problème apparaît, change ou disparaît (une fois par changement, jamais en boucle).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['table', 'json'], true)) {
            $io->error('--format : table ou json.');

            return Command::INVALID;
        }

        $report = $this->health->run(new \DateTimeImmutable());
        $status = $report->status();

        if ($format === 'json') {
            $output->writeln(json_encode([
                'status' => $status->value,
                'generated_at' => $report->generatedAt->format(\DATE_ATOM),
                'checks' => array_map(static fn ($check): array => ['name' => $check->name, 'status' => $check->status->value, 'message' => $check->message], $report->checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $io->title('JOBSCAN — Santé');
            $io->table(['', 'Contrôle', 'État'], array_map(static fn ($check): array => [$check->status->symbol(), $check->name, OutputFormatterEscape::escape($check->message)], $report->checks));
            match ($status) {
                HealthStatus::Ok => $io->success('Tout est en ordre.'),
                HealthStatus::Warning => $io->warning(sprintf('%d point(s) à surveiller.', count($report->problems()))),
                HealthStatus::Critical => $io->error(sprintf('%d problème(s), dont au moins un critique.', count($report->problems()))),
            };
        }

        if ((bool) $input->getOption('alert')) {
            $this->alert($report, $io, $format === 'table');
        }

        return match (true) {
            $status === HealthStatus::Critical => Command::FAILURE,
            $status === HealthStatus::Warning && (bool) $input->getOption('strict') => Command::FAILURE,
            default => Command::SUCCESS,
        };
    }

    private function alert(\App\Health\HealthReport $report, SymfonyStyle $io, bool $verbose): void
    {
        $signature = $report->signature();
        $previous = $this->state->read() ?? '';
        if ($signature === $previous) {
            return;
        }

        if ($report->problems() === []) {
            $message = '✅ <b>Jobscan</b> : tout est rétabli.';
        } else {
            $lines = [sprintf('⚠️ <b>Jobscan</b> : %d problème(s)', count($report->problems()))];
            foreach ($report->problems() as $problem) {
                $lines[] = sprintf('%s %s : %s', $problem->status->symbol(), TelegramHtml::escape($problem->name), TelegramHtml::escape(mb_substr($problem->message, 0, 200)));
            }

            $message = implode("\n", $lines);
        }

        // L'empreinte n'est mémorisée que si Telegram a reçu l'alerte : sinon elle est retentée au prochain contrôle.
        if ($this->telegram->send($message, $report->status() !== HealthStatus::Critical, false)) {
            $this->state->write($signature);
            if ($verbose) {
                $io->writeln('Alerte Telegram envoyée.');
            }
        } elseif ($verbose) {
            $io->warning('Alerte Telegram non envoyée (retentée au prochain contrôle).');
        }
    }
}
