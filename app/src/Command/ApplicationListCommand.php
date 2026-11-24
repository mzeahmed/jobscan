<?php

declare(strict_types=1);

namespace App\Command;

use App\Tracking\ApplicationStatus;
use App\Repository\ApplicationRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:applications:list',
    description: 'Liste les candidatures suivies, avec leur statut.',
)]
final class ApplicationListCommand extends Command
{
    public function __construct(private readonly ApplicationRepository $applications)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filtre sur un statut.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de candidatures.', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Candidatures');

        $statuses = null;
        $filter = \App\Tracking\InputReader::optionalString($input->getOption('status'));
        if ($filter !== null) {
            $status = ApplicationStatus::tryFrom($filter);
            if ($status === null) {
                $io->error(sprintf('Statut inconnu : « %s ».', $filter));

                return Command::INVALID;
            }
            $statuses = [$status];
        }

        $limit = \App\Tracking\InputReader::id($input->getOption('limit'));
        if ($limit === null) {
            $io->error('--limit doit être un entier positif.');

            return Command::INVALID;
        }

        $applications = $this->applications->findByStatuses($statuses, $limit);
        if ($applications === []) {
            $io->note('Aucune candidature. Ouvrez-en une : php bin/console app:applications:track <offre>');

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();
        $rows = [];
        foreach ($applications as $application) {
            $job = $application->getJob();
            $rows[] = [
                $application->getId(),
                $application->getStatus()->label(),
                mb_strimwidth((string) $job->getTitle(), 0, 45, '…'),
                $job->getCompany() ?? '—',
                $application->getAppliedAt()?->format('d/m/Y') ?? '—',
                $application->getCvVersionLabel() ?? '—',
                ($application->getFollowUpAt()?->format('d/m/Y') ?? '—') . ($application->isFollowUpDue($now) ? ' ⏰' : ''),
            ];
        }
        $io->table(['#', 'Statut', 'Poste', 'Entreprise', 'Postulé le', 'CV', 'Relance'], $rows);

        $counts = $this->applications->countByStatus();
        $parts = [];
        foreach (ApplicationStatus::cases() as $status) {
            if (($counts[$status->value] ?? 0) > 0) {
                $parts[] = sprintf('%s : %d', $status->label(), $counts[$status->value]);
            }
        }
        $io->writeln(implode(' · ', $parts));

        return Command::SUCCESS;
    }
}
