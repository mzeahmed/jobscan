<?php

declare(strict_types=1);

namespace App\Command;

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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse les offres sans persistance ni notification.')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Provider à exécuter (remoteok, rss, searxng).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $start = microtime(true);

        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Pipeline offres freelance/CDI PHP/Symfony/WordPress');

        $dryRun = (bool) $input->getOption('dry-run');
        /** @var list<string> $selectedProviders */
        $selectedProviders = array_map('strtolower', (array) $input->getOption('provider'));
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

            $name = new \ReflectionClass($provider)->getShortName();
            $io->section('Provider : ' . $name);

            $jobs = $provider->fetch();
            $io->writeln(sprintf('  → <info>%d</info> offre(s) récupérée(s)', \count($jobs)));
            $fetched += \count($jobs);

            foreach ($jobs as $dto) {
                try {
                    $result = $this->processor->process($dto, $dryRun);
                } catch (\Throwable $e) {
                    $result = JobProcessingResult::failed($e->getMessage());
                    $io->warning(sprintf('Échec du traitement de "%s" : %s', $dto->title, $e->getMessage()));
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
