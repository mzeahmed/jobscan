<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\JobRepository;
use App\Processor\JobProcessingResult;
use App\Processor\JobProcessingStatus;
use App\Provider\JobProviderInterface;
use App\Processor\JobProcessorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse les offres sans persistance ni notification.')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Vide la table des offres avant le pipeline (hors production).')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Provider à exécuter (remoteok, rss, searxng).')
            ->addOption('skip-health-check', null, InputOption::VALUE_NONE, 'Ne vérifie pas la disponibilité des providers avant le run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $start = microtime(true);

        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Pipeline offres freelance/CDI PHP/Symfony/WordPress');

        $dryRun = (bool) $input->getOption('dry-run');
        $reset = (bool) $input->getOption('reset');
        $skipHealthCheck = (bool) $input->getOption('skip-health-check');
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
        $analyzedByAI = 0;
        $analyzedByFallback = 0;
        $notified = 0;
        $counts = array_fill_keys(array_map(static fn (JobProcessingStatus $status): string => $status->value, JobProcessingStatus::cases()), 0);

        foreach ($providers as $provider) {
            if ($selectedProviders !== [] && !\in_array($provider->name(), $selectedProviders, true)) {
                continue;
            }

            if (!$skipHealthCheck && !$provider->isHealthy()) {
                $io->warning(sprintf('Provider "%s" indisponible — ignoré pour ce run.', $provider->name()));

                continue;
            }

            $name = new \ReflectionClass($provider)->getShortName();
            $io->section('Provider : ' . $name);

            $jobs = $provider->fetch();
            $io->writeln(sprintf('  → <info>%d</info> offre(s) récupérée(s)', \count($jobs)));
            $fetched += \count($jobs);

            try {
                $results = $this->processor->processBatch($jobs, $dryRun);
            } catch (\Throwable $e) {
                $results = array_fill(0, count($jobs), JobProcessingResult::failed($e->getMessage()));
            }

            foreach ($results as $index => $result) {
                if ($result->status === JobProcessingStatus::Failed) {
                    $io->warning(sprintf(
                        'Échec du traitement de "%s" : %s',
                        $jobs[$index]->title ?? 'offre inconnue',
                        $result->error ?? 'erreur inconnue',
                    ));
                }

                ++$counts[$result->status->value];
                if ($result->score !== null) {
                    $result->usedFallback ? ++$analyzedByFallback : ++$analyzedByAI;
                }
                if ($result->notified) {
                    ++$notified;
                }
            }
        }

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
            ['Sauvegardées', $counts[JobProcessingStatus::Saved->value]],
            ['Dry-run', $counts[JobProcessingStatus::DryRun->value]],
            ['Notifiées', $notified],
            ['Échecs', $counts[JobProcessingStatus::Failed->value]],
        ]);

        $io->success(sprintf('Pipeline terminé en %.2f s.', $time));

        return Command::SUCCESS;
    }
}
