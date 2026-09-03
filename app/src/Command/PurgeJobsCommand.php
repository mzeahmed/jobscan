<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\JobRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Supprime les offres d'emploi ingérées il y a plus longtemps qu'un seuil configurable.
 *
 * Les offres s'accumulent indéfiniment dans SQLite ; cette commande fournit un
 * nettoyage sûr, avec un mode `--dry-run` pour prévisualiser le volume concerné
 * sans rien supprimer.
 */
#[AsCommand(
    name: 'app:jobs:purge',
    description: "Supprime les offres d'emploi plus anciennes qu'un seuil (par date d'ingestion).",
)]
final class PurgeJobsCommand extends Command
{
    private const string DEFAULT_OLDER_THAN = '30d';

    public function __construct(private readonly JobRepository $jobRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Âge minimum des offres à supprimer : Nd (jours) ou Nw (semaines).',
                self::DEFAULT_OLDER_THAN,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Affiche le nombre d\'offres qui seraient supprimées sans rien supprimer.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Purge des offres');

        $rawOlderThan = (string) $input->getOption('older-than');
        $before = $this->resolveThreshold($rawOlderThan);

        if ($before === null) {
            $io->error(sprintf(
                'Valeur --older-than invalide : "%s". Format attendu : Nd (jours) ou Nw (semaines), ex. 30d ou 4w.',
                $rawOlderThan,
            ));

            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $count = $this->jobRepository->countCreatedBefore($before);
            $io->note(sprintf(
                'Mode dry-run : %d offre(s) antérieure(s) au %s seraient supprimées.',
                $count,
                $before->format('Y-m-d H:i'),
            ));

            return Command::SUCCESS;
        }

        $deleted = $this->jobRepository->deleteCreatedBefore($before);
        $io->success(sprintf(
            '%d offre(s) supprimée(s) (antérieures au %s).',
            $deleted,
            $before->format('Y-m-d H:i'),
        ));

        return Command::SUCCESS;
    }

    /**
     * Convertit `30d` / `4w` en date pivot. Retourne `null` si le format est invalide.
     */
    private function resolveThreshold(string $expression): ?\DateTimeImmutable
    {
        if (!preg_match('/^\s*(\d+)\s*([dw])\s*$/i', $expression, $matches)) {
            return null;
        }

        $amount = (int) $matches[1];
        $unit = strtolower($matches[2]) === 'w' ? 'weeks' : 'days';

        return new \DateTimeImmutable(sprintf('-%d %s', $amount, $unit));
    }
}
