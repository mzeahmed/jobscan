<?php

declare(strict_types=1);

namespace App\Command;

use App\Profile\ProfileLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Crée les fichiers locaux du profil depuis les exemples versionnés.
 *
 * Ne remplace jamais un fichier existant. Les fichiers créés ne sont lisibles que par leur
 * propriétaire (ils contiennent des données personnelles) et leurs valeurs d'exemple sont fictives.
 */
#[AsCommand(
    name: 'app:profile:init',
    description: 'Crée candidate.yaml et search_profiles.yaml locaux depuis les exemples (sans jamais écraser).',
)]
final class InitProfileCommand extends Command
{
    public function __construct(
        private readonly ProfileLoader $loader,
        private readonly string $examplesDirectory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Initialisation du profil');

        $directory = rtrim($this->loader->directory(), '/');
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            $io->error(sprintf('Impossible de créer le dossier %s.', $directory));

            return Command::FAILURE;
        }

        $created = [];
        foreach ([ProfileLoader::CANDIDATE_FILE, ProfileLoader::SEARCH_PROFILES_FILE] as $file) {
            $target = $directory . '/' . $file;
            $source = rtrim($this->examplesDirectory, '/') . '/' . str_replace('.yaml', '.example.yaml', $file);

            if (is_file($target)) {
                $io->writeln(sprintf('  <comment>conservé</comment>  %s (existe déjà, non modifié)', $file));

                continue;
            }

            if (!is_file($source) || !copy($source, $target)) {
                $io->error(sprintf('Impossible de créer %s depuis l\'exemple.', $file));

                return Command::FAILURE;
            }

            chmod($target, 0o600);
            $created[] = $file;
            $io->writeln(sprintf('  <info>créé</info>      %s', $file));
        }

        if ($created === []) {
            $io->note('Aucun fichier créé : tout existe déjà.');

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            'Les valeurs des fichiers créés sont des EXEMPLES fictifs (compétences comprises). Éditez-les dans %s, puis lancez : php bin/console app:profile:validate',
            $directory,
        ));

        return Command::SUCCESS;
    }
}
