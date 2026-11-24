<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use App\Analysis\PendingAnalysisRunnerInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reprend les analyses des offres persistées : en attente, analysées par l'heuristique
 * (LLM indisponible lors de la collecte) ou en échec, selon la politique de reprise.
 */
#[AsCommand(
    name: 'app:jobs:analyze',
    description: 'Analyse (ou réanalyse) les offres en attente, en échec ou analysées par heuristique.',
)]
final class AnalyzeJobsCommand extends Command
{
    public function __construct(
        private readonly PendingAnalysisRunnerInterface $runner,
        private readonly int $defaultLimit = 50,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, "Nombre maximal d'offres à traiter.", (string) $this->defaultLimit)
            ->addOption('outdated', null, InputOption::VALUE_NONE, "Ré-extrait les exigences des offres déjà analysées dont le schéma d'extraction est périmé (sans notifier).");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Reprise des analyses');

        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1) {
            $io->error('--limit doit être un entier positif.');

            return Command::INVALID;
        }

        if ((bool) $input->getOption('outdated')) {
            return $this->reextract($io, $limit);
        }

        $summary = $this->runner->run($limit);

        $io->table(['Indicateur', 'Total'], [
            ['Analysées par IA', $summary->analyzedByAi],
            ['Analysées par heuristique (à retenter)', $summary->analyzedByFallback],
            ['En échec', $summary->failed],
            ['Notifiées', $summary->notified],
        ]);

        if ($summary->total() === 0) {
            $io->note('Aucune offre à analyser pour le moment.');
        }

        return Command::SUCCESS;
    }

    private function reextract(SymfonyStyle $io, int $limit): int
    {
        $summary = $this->runner->reextractOutdated($limit);

        $io->table(['Indicateur', 'Total'], [
            ['Exigences mises à jour', $summary->analyzedByAi],
            ['Laissées inchangées (LLM indisponible)', $summary->skipped],
            ['En échec', $summary->failed],
        ]);

        if ($summary->analyzedByAi + $summary->skipped + $summary->failed === 0) {
            $io->note('Aucune offre à ré-extraire : toutes suivent le schéma courant.');
        } elseif ($summary->skipped > 0) {
            $io->warning('Le LLM était indisponible pour certaines offres : elles restent inchangées. Relancez plus tard.');
        }

        return Command::SUCCESS;
    }
}
