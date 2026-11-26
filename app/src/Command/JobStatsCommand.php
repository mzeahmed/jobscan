<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\JobRepository;
use App\AI\Extraction\JobRequirements;
use App\Repository\ProviderRunRepository;
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
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly ProviderRunRepository $providerRunRepository,
    ) {
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

        $io->section("Statut d'analyse");
        $analysis = [];
        foreach ($this->jobRepository->countByAnalysisStatus() as $status => $total) {
            $analysis[] = [$status, $total];
        }

        $io->table(['Statut', 'Total'], $analysis);
        $outdated = $this->jobRepository->countOutdatedExtraction(JobRequirements::SCHEMA_VERSION);
        if ($outdated > 0) {
            $io->note(sprintf(
                '%d offre(s) analysée(s) sans exigences structurées à jour (schéma v%d). Mise à jour : php bin/console app:jobs:analyze --outdated',
                $outdated,
                JobRequirements::SCHEMA_VERSION,
            ));
        }

        $io->section('Santé des providers (dernière exécution)');
        $lastSuccess = $this->providerRunRepository->findLastSuccessByProvider();
        $runs = [];
        foreach ($this->providerRunRepository->findLatestByProvider() as $provider => $run) {
            $runs[] = [
                $provider,
                $run->getStatus()->value,
                $run->getStartedAt()->format('Y-m-d H:i'),
                isset($lastSuccess[$provider]) ? $lastSuccess[$provider]->format('Y-m-d H:i') : '—',
                sprintf('%d / %d / %d', $run->getFetchedCount(), $run->getNewCount(), $run->getDuplicateCount()),
                $run->getErrorCount(),
                sprintf('%.1f s', $run->getDurationMs() / 1000),
                $run->getLastError() === null ? '' : mb_substr($run->getLastError(), 0, 60),
            ];
        }

        $io->table(
            ['Provider', 'Statut', 'Dernier run', 'Dernier succès', 'Récup. / nouv. / doublons', 'Erreurs', 'Durée', 'Dernière erreur'],
            $runs,
        );

        return Command::SUCCESS;
    }
}
