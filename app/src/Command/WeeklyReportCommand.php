<?php

declare(strict_types=1);

namespace App\Command;

use App\Report\PeriodicReport;
use App\Notification\TelegramNotifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:report:weekly',
    description: 'Rapport de période : collecte, offres à trier, candidatures, relances à faire et santé (Markdown, fichier ou Telegram).',
)]
final class WeeklyReportCommand extends Command
{
    public function __construct(
        private readonly PeriodicReport $report,
        private readonly TelegramNotifier $telegram,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Longueur de la période, en jours (1 à 365).', '7')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Écrit le rapport Markdown dans ce fichier au lieu de l\'afficher.')
            ->addOption('telegram', null, InputOption::VALUE_NONE, 'Envoie aussi un résumé sur Telegram (silencieux).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = \App\Tracking\InputReader::id($input->getOption('days'));
        if ($days === null || $days > 365) {
            $io->error('--days doit être un entier entre 1 et 365.');

            return Command::INVALID;
        }

        $data = $this->report->build(new \DateTimeImmutable(), $days);
        $markdown = $this->report->markdown($data);

        $file = $input->getOption('output');
        if (is_string($file) && $file !== '') {
            if (is_dir($file) || @file_put_contents($file, $markdown) === false) {
                $io->error(sprintf('Impossible d\'écrire dans « %s ».', $file));

                return Command::FAILURE;
            }

            $io->success(sprintf('Rapport écrit dans %s.', $file));
        } else {
            $output->write($markdown);
        }

        if ((bool) $input->getOption('telegram')) {
            if (!$this->telegram->send($this->report->telegram($data), true, false)) {
                $io->error('Telegram n\'a pas reçu le rapport.');

                return Command::FAILURE;
            }

            $io->writeln('Résumé envoyé sur Telegram.');
        }

        return Command::SUCCESS;
    }
}
