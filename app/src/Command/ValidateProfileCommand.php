<?php

declare(strict_types=1);

namespace App\Command;

use App\Profile\ProfileLoader;
use App\Profile\InvalidProfileException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Valide les fichiers locaux du profil candidat et des profils de recherche.
 *
 * N'affiche que des compteurs, les noms de profils de recherche et les avertissements : le contenu
 * du profil (données personnelles) n'est jamais reproduit.
 */
#[AsCommand(
    name: 'app:profile:validate',
    description: 'Valide le profil candidat et les profils de recherche (fichiers YAML locaux).',
)]
final class ValidateProfileCommand extends Command
{
    /** @param string $examplesDirectory Dossier des fichiers d'exemple versionnés */
    public function __construct(
        private readonly ProfileLoader $loader,
        private readonly string $examplesDirectory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Validation du profil');

        try {
            $set = $this->loader->load();
        } catch (InvalidProfileException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $directory = $this->loader->directory();
        $candidate = $set->candidate;

        if ($candidate === null && $set->searchProfiles === []) {
            $io->warning(sprintf(
                "Aucun profil trouvé dans %s.\nCréez-le avec : php bin/console app:profile:init",
                $directory,
            ));

            return Command::SUCCESS;
        }

        if ($candidate !== null) {
            $io->section('Profil candidat');
            $io->table(['Élément', 'Nombre'], [
                ['Compétences déclarées', count($candidate->skills)],
                ['Compétences exclues', count($candidate->excludedSkills)],
                ['Intitulés recherchés', count($candidate->targetTitles)],
                ['Expériences', count($candidate->experiences)],
                ['Projets', count($candidate->projects)],
                ['Certifications', count($candidate->certifications)],
                ['Langues', count($candidate->languages)],
                ['Versions de CV', count($candidate->cvVersions)],
            ]);
        }

        if ($set->searchProfiles !== []) {
            $io->section('Profils de recherche');
            $rows = [];
            foreach ($set->searchProfiles as $profile) {
                $rows[] = [
                    $profile->id,
                    $profile->name,
                    $profile->enabled ? 'oui' : 'non',
                    $profile->minMatchScore,
                    count($profile->primarySkills) . ' / ' . count($profile->secondarySkills),
                    $profile->cvId ?? '—',
                ];
            }
            $io->table(['Id', 'Nom', 'Actif', 'Seuil', 'Compétences prio. / sec.', 'CV'], $rows);
        }

        $warnings = [...$set->warnings, ...$this->untouchedExamples()];
        foreach ($warnings as $warning) {
            $io->warning($warning);
        }

        $io->success($warnings === [] ? 'Profil valide.' : sprintf('Profil valide, %d avertissement(s).', count($warnings)));

        return Command::SUCCESS;
    }

    /**
     * Un fichier local identique à l'exemple contient des valeurs fictives non revues, dont des
     * compétences que le candidat ne possède peut-être pas.
     *
     * @return list<string>
     */
    private function untouchedExamples(): array
    {
        $warnings = [];

        foreach ([ProfileLoader::CANDIDATE_FILE, ProfileLoader::SEARCH_PROFILES_FILE] as $file) {
            $local = rtrim($this->loader->directory(), '/') . '/' . $file;
            $example = rtrim($this->examplesDirectory, '/') . '/' . str_replace('.yaml', '.example.yaml', $file);

            if (is_file($local) && is_file($example) && hash_file('sha256', $local) === hash_file('sha256', $example)) {
                $warnings[] = sprintf('%s est identique à l\'exemple : ses valeurs sont fictives. Remplacez-les par les vôtres.', $file);
            }
        }

        return $warnings;
    }
}
