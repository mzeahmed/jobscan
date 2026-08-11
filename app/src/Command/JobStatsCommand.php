<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\JobRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:jobs:stats',
    description: "Affiche les statistiques des offres d'emploi enregistrées.",
)]
final class JobStatsCommand extends Command
{
    public function __construct(private readonly JobRepository $jobRepository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Statistiques');
        $io->table(['Indicateur', 'Total'], [
            ['Offres enregistrées', $this->jobRepository->countAll()],
            ["Ajoutées aujourd'hui", $this->jobRepository->countToday()],
            ['Score moyen', number_format($this->jobRepository->averageScore(), 1, ',', ' ')],
            ['Score ≥ 80', $this->jobRepository->countByScoreRange(80, null)],
            ['Score 60–79', $this->jobRepository->countByScoreRange(60, 79)],
            ['Score < 60', $this->jobRepository->countByScoreRange(null, 59)],
            ['Notifiées', $this->jobRepository->countNotified()],
        ]);

        $sources = [];
        foreach ($this->jobRepository->countBySource() as $source => $total) {
            $sources[] = [$source, $total];
        }
        $io->section('Par source');
        $io->table(['Source', 'Total'], $sources);

        return Command::SUCCESS;
    }
}
