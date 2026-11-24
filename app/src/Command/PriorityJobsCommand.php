<?php

declare(strict_types=1);

namespace App\Command;

use App\Matching\PublicationAge;
use App\Repository\JobMatchRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Classe les offres compatibles par priorité (compatibilité et fraîcheur).
 */
#[AsCommand(
    name: 'app:jobs:priority',
    description: 'Liste les offres les plus prioritaires, avec leur profil de recherche le plus pertinent.',
)]
final class PriorityJobsCommand extends Command
{
    public function __construct(private readonly JobMatchRepository $matchRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, "Nombre d'offres à afficher.", '20')
            ->addOption('search-profile', null, InputOption::VALUE_REQUIRED, 'Identifiant d\'un profil de recherche (sinon : le plus pertinent de chaque offre).')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Inclut aussi les offres éliminées, sous le seuil ou aux données insuffisantes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Offres prioritaires');

        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!is_int($limit) || $limit < 1) {
            $io->error('--limit doit être un entier positif.');

            return Command::INVALID;
        }

        $profile = $input->getOption('search-profile');
        $matches = $this->matchRepository->findTop($limit, is_string($profile) && $profile !== '' ? $profile : null, !(bool) $input->getOption('all'));

        if ($matches === []) {
            $io->note('Aucune offre à afficher. Lancez le matching : php bin/console app:jobs:match');

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();
        $rows = [];
        foreach ($matches as $match) {
            $job = $match->getJob();
            $rows[] = [
                $job->getId(),
                mb_strimwidth((string) $job->getTitle(), 0, 44, '…'),
                mb_strimwidth((string) ($job->getCompany() ?? '—'), 0, 20, '…'),
                $match->getSearchProfileId(),
                $match->getMatchScore(),
                $match->getFreshnessScore(),
                $match->getPriorityScore(),
                $match->getCoverage() . ' %',
                $match->getVerdict()->label(),
                PublicationAge::describe($job, $now),
            ];
        }

        $io->table(['Id', 'Titre', 'Entreprise', 'Profil', 'Compat.', 'Fraîch.', 'Priorité', 'Couv.', 'Verdict', 'Âge'], $rows);
        $io->writeln('Détail d\'une offre : <info>php bin/console app:jobs:explain <id></info>');

        return Command::SUCCESS;
    }
}
