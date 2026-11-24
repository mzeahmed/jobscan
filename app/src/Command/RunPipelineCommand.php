<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ProviderRun;
use App\Analysis\AnalysisSummary;
use App\Repository\JobRepository;
use App\Monitoring\FetchDiagnostics;
use App\Monitoring\ProviderRunStatus;
use App\Processor\JobProcessingResult;
use App\Processor\JobProcessingStatus;
use App\Provider\JobProviderInterface;
use App\Processor\JobProcessorInterface;
use App\Repository\ProviderRunRepository;
use Symfony\Component\Console\Command\Command;
use App\Analysis\PendingAnalysisRunnerInterface;
use Symfony\Component\Console\Input\InputOption;
use App\Provider\IngestionAwareProviderInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:jobs:run',
    description: "Lance le pipeline d'agrégation et de scoring des offres d'emploi freelance.",
)]
final class RunPipelineCommand extends Command
{
    /**
     * @param  iterable<JobProviderInterface>  $providers  Injecté via tagged_iterator app.job_provider
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly JobProcessorInterface $processor,
        private readonly JobRepository $jobRepository,
        private readonly string $environment,
        private readonly PendingAnalysisRunnerInterface $analysisRunner,
        private readonly ProviderRunRepository $providerRunRepository,
        private readonly FetchDiagnostics $diagnostics,
        private readonly int $analysisLimit = 50,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse les offres sans persistance ni notification.')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Vide la table des offres avant le pipeline (hors production).')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Provider à exécuter (remoteok, rss, searxng).')
            ->addOption('skip-health-check', null, InputOption::VALUE_NONE, 'Ne vérifie pas la disponibilité des providers avant le run.')
            ->addOption('no-analyze', null, InputOption::VALUE_NONE, "Collecte et persiste sans lancer l'analyse IA (voir app:jobs:analyze).");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $start = microtime(true);

        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Pipeline offres freelance/CDI PHP/Symfony/WordPress');

        $dryRun = (bool) $input->getOption('dry-run');
        $reset = (bool) $input->getOption('reset');
        $skipHealthCheck = (bool) $input->getOption('skip-health-check');
        $noAnalyze = (bool) $input->getOption('no-analyze');
        /** @var list<string> $selectedProviders */
        $selectedProviders = array_map(strtolower(...), (array) $input->getOption('provider'));
        $providers = iterator_to_array($this->providers);
        $availableProviders = array_map(static fn (JobProviderInterface $provider): string => $provider->name(), $providers);
        $unknownProviders = array_diff($selectedProviders, $availableProviders);
        if ($unknownProviders !== []) {
            $io->error(sprintf(
                'Provider(s) inconnu(s) : %s. Valeurs disponibles : %s.',
                implode(', ', $unknownProviders),
                implode(', ', $availableProviders),
            ));

            return Command::INVALID;
        }

        if ($reset && $dryRun) {
            $io->error('Les options --reset et --dry-run ne peuvent pas être utilisées ensemble.');

            return Command::INVALID;
        }

        if ($reset && $this->environment === 'prod') {
            $io->error("L'option --reset est interdite en environnement de production.");

            return Command::FAILURE;
        }

        if ($reset) {
            $deleted = $this->jobRepository->truncate();
            $io->warning(sprintf('Base de développement réinitialisée : %d offre(s) supprimée(s).', $deleted));
        }

        if ($dryRun) {
            $io->note('Mode dry-run : aucune écriture en base ni notification ne sera effectuée.');
        }

        $fetched = 0;
        $notified = 0;
        $counts = array_fill_keys(array_map(static fn (JobProcessingStatus $status): string => $status->value, JobProcessingStatus::cases()), 0);
        $dryRunScored = 0;
        $dryRunFallback = 0;

        foreach ($providers as $provider) {
            if ($selectedProviders !== [] && !\in_array($provider->name(), $selectedProviders, true)) {
                continue;
            }

            $providerStart = new \DateTimeImmutable();
            $providerClock = microtime(true);

            if (!$skipHealthCheck && !$provider->isHealthy()) {
                $io->warning(sprintf('Provider "%s" indisponible — ignoré pour ce run.', $provider->name()));
                $this->recordRun($dryRun, $provider->name(), $providerStart, $providerClock, ProviderRunStatus::Failed, 0, 0, 0, 1, 'Sonde de disponibilité en échec.');

                continue;
            }

            $name = new \ReflectionClass($provider)->getShortName();
            $io->section('Provider : ' . $name);

            $errors = [];
            try {
                $jobs = $provider->fetch();
            } catch (\Throwable $e) {
                $jobs = [];
                $errors[] = $e->getMessage();
            }
            $report = $this->diagnostics->drain($provider->name());
            $errors = [...$errors, ...$report['errors']];

            if ($report['disabled']) {
                $io->writeln('  → <comment>source désactivée (non configurée)</comment>');
            } else {
                $io->writeln(sprintf('  → <info>%d</info> offre(s) récupérée(s)', \count($jobs)));
            }
            $fetched += \count($jobs);

            try {
                $results = $this->processor->processBatch($jobs, $dryRun);
            } catch (\Throwable $e) {
                $results = array_fill(0, count($jobs), JobProcessingResult::failed($e->getMessage()));
            }

            if (!$dryRun && $provider instanceof IngestionAwareProviderInterface) {
                try {
                    $provider->acknowledge($jobs, $results);
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }

            $providerNew = 0;
            $providerDuplicates = 0;
            foreach ($results as $index => $result) {
                if ($result->status === JobProcessingStatus::Failed) {
                    $errors[] = $result->error ?? 'erreur inconnue';
                    $io->warning(sprintf(
                        'Échec du traitement de "%s" : %s',
                        $jobs[$index]->title ?? 'offre inconnue',
                        $result->error ?? 'erreur inconnue',
                    ));
                }

                ++$counts[$result->status->value];
                if ($result->status === JobProcessingStatus::Saved) {
                    ++$providerNew;
                }
                if ($result->status === JobProcessingStatus::Duplicate) {
                    ++$providerDuplicates;
                }
                if ($result->status === JobProcessingStatus::DryRun && $result->score !== null) {
                    $result->usedFallback ? ++$dryRunFallback : ++$dryRunScored;
                }
            }

            $this->recordRun(
                $dryRun,
                $provider->name(),
                $providerStart,
                $providerClock,
                ProviderRunStatus::classify($report['disabled'], \count($jobs), $providerNew, \count($errors)),
                \count($jobs),
                $providerNew,
                $providerDuplicates,
                \count($errors),
                $errors === [] ? null : end($errors),
            );
        }

        $analysis = new AnalysisSummary();
        if (!$dryRun && !$noAnalyze) {
            $io->section('Analyse des offres en attente');
            $analysis = $this->analysisRunner->run($this->analysisLimit);
            $notified = $analysis->notified;
            $io->writeln(sprintf(
                '  → <info>%d</info> offre(s) traitée(s) (%d IA, %d heuristique, %d en échec)',
                $analysis->total(),
                $analysis->analyzedByAi,
                $analysis->analyzedByFallback,
                $analysis->failed,
            ));
        }
        $analyzedByAI = $analysis->analyzedByAi + $dryRunScored;
        $analyzedByFallback = $analysis->analyzedByFallback + $dryRunFallback;

        $end = microtime(true);
        $time = $end - $start;

        $io->section('Résumé');
        $io->table(['Indicateur', 'Total'], [
            ['Récupérées', $fetched],
            ['Filtrées', $counts[JobProcessingStatus::Filtered->value]],
            ['Trop anciennes', $counts[JobProcessingStatus::TooOld->value]],
            ['Doublons', $counts[JobProcessingStatus::Duplicate->value]],
            ['Pré-score insuffisant', $counts[JobProcessingStatus::LowPrescore->value]],
            ['Analysées par IA', $analyzedByAI],
            ['Analysées par fallback', $analyzedByFallback],
            ['Sauvegardées (analyse en attente)', $counts[JobProcessingStatus::Saved->value]],
            ['Analyses en échec', $analysis->failed],
            ['Dry-run', $counts[JobProcessingStatus::DryRun->value]],
            ['Notifiées', $notified],
            ['Échecs', $counts[JobProcessingStatus::Failed->value]],
        ]);

        $io->success(sprintf('Pipeline terminé en %.2f s.', $time));

        return Command::SUCCESS;
    }

    private function recordRun(
        bool $dryRun,
        string $provider,
        \DateTimeImmutable $startedAt,
        float $startClock,
        ProviderRunStatus $status,
        int $fetched,
        int $new,
        int $duplicates,
        int $errors,
        ?string $lastError,
    ): void {
        if ($dryRun) {
            return;
        }

        try {
            $this->providerRunRepository->save(new ProviderRun(
                $provider,
                $startedAt,
                (int) round((microtime(true) - $startClock) * 1000),
                $status,
                $fetched,
                $new,
                $duplicates,
                $errors,
                $lastError,
            ));
        } catch (\Throwable) {
            // Le monitoring ne doit jamais faire échouer la collecte.
        }
    }
}
