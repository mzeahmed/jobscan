<?php

declare(strict_types=1);

namespace App\Command;

use App\Matching\JobMatcher;
use App\Profile\ProfileRegistry;
use App\Repository\JobRepository;
use App\Repository\JobMatchRepository;
use App\Profile\InvalidProfileException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Apparie les offres analysées aux profils de recherche actifs, sans aucun appel au LLM.
 *
 * Par défaut, seuls les résultats manquants ou périmés sont calculés (profil, règles ou analyse
 * modifiés). `--all` recalcule tout ; `--priorities` ne rafraîchit que fraîcheur et priorité, à lancer
 * régulièrement puisque le temps passe, ou après un changement des poids de priorité ou du barème de fraîcheur.
 */
#[AsCommand(
    name: 'app:jobs:match',
    description: 'Apparie les offres aux profils de recherche et calcule compatibilité, fraîcheur et priorité (sans LLM).',
)]
final class MatchJobsCommand extends Command
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly JobMatchRepository $matchRepository,
        private readonly JobMatcher $matcher,
        private readonly ProfileRegistry $profiles,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Recalcule tous les résultats, même à jour.')
            ->addOption('priorities', null, InputOption::VALUE_NONE, 'Rafraîchit seulement la fraîcheur et la priorité (sans refaire le matching).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Matching des offres');

        try {
            $set = $this->profiles->profiles();
        } catch (InvalidProfileException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($set->candidate === null || $set->enabledSearchProfiles() === []) {
            $io->warning("Aucun profil candidat ou aucun profil de recherche actif : rien à apparier.\nCréez-les avec : php bin/console app:profile:init");

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();

        if ((bool) $input->getOption('priorities')) {
            $updated = $this->refreshPriorities($now);
            $io->success(sprintf('Fraîcheur et priorité rafraîchies pour %d résultat(s).', $updated));

            return Command::SUCCESS;
        }

        $force = (bool) $input->getOption('all');
        $removed = $this->matcher->removeObsolete();
        $examined = $computed = 0;
        $afterId = 0;

        while (($jobs = $this->jobRepository->findWithRequirementsAfterId($afterId, self::BATCH_SIZE)) !== []) {
            foreach ($jobs as $job) {
                ++$examined;
                $computed += count($this->matcher->matchJob($job, $now, $force));
                $afterId = max($afterId, (int) $job->getId());
            }

            $this->jobRepository->flush();
            $this->jobRepository->clear();
        }

        $io->table(['Indicateur', 'Total'], [
            ['Profils de recherche actifs', count($set->enabledSearchProfiles())],
            ['Offres avec exigences extraites', $examined],
            ['Résultats calculés', $computed],
            ['Résultats déjà à jour', max(0, $examined * count($set->enabledSearchProfiles()) - $computed)],
            ['Résultats supprimés (profils inactifs)', $removed],
        ]);

        $verdicts = [];
        foreach ($this->matchRepository->countByVerdict() as $verdict => $total) {
            $verdicts[] = [$verdict, $total];
        }

        if ($verdicts !== []) {
            $io->section('Résultats par verdict');
            $io->table(['Verdict', 'Total'], $verdicts);
        }

        if ($examined === 0) {
            $io->note("Aucune offre n'a d'exigences extraites. Analysez-les : php bin/console app:jobs:analyze (--outdated pour les anciennes).");
        }

        return Command::SUCCESS;
    }

    private function refreshPriorities(\DateTimeImmutable $now): int
    {
        $updated = 0;
        $afterId = 0;

        while (($matches = $this->matchRepository->findBatchAfterId($afterId, self::BATCH_SIZE)) !== []) {
            $updated += $this->matcher->refreshPriorities($matches, $now);
            $afterId = (int) end($matches)->getId();

            $this->jobRepository->flush();
            $this->jobRepository->clear();
        }

        return $updated;
    }
}
