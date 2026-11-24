<?php

declare(strict_types=1);

namespace App\Command;

use App\Cv\CvInspector;
use App\Profile\ProfileRegistry;
use App\Profile\InvalidProfileException;
use App\Repository\ApplicationRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:cv:list',
    description: 'Liste les versions de CV déclarées : état du fichier, profils qui les utilisent, candidatures et réponses.',
)]
final class CvListCommand extends Command
{
    public function __construct(
        private readonly ProfileRegistry $profiles,
        private readonly CvInspector $inspector,
        private readonly ApplicationRepository $applications,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Versions de CV');

        try {
            $candidate = $this->profiles->candidate();
            $searchProfiles = $this->profiles->enabledSearchProfiles();
        } catch (InvalidProfileException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($candidate === null || $candidate->cvVersions === []) {
            $io->note('Aucune version de CV déclarée. Ajoutez une section `cvs` à candidate.yaml (voir docs/PROFILE.md).');

            return Command::SUCCESS;
        }

        $stats = $this->applications->statsByCv();
        $rows = [];
        $problems = 0;
        foreach ($candidate->cvVersions as $cv) {
            $status = $this->inspector->inspect($cv);
            $problems += $status->isUsable() ? 0 : 1;
            $usedBy = array_map(static fn ($p): string => $p->id, array_filter($searchProfiles, static fn ($p): bool => $p->cvId === $cv->id));
            $sent = $stats[$cv->id]['sent'] ?? 0;
            $responded = $stats[$cv->id]['responded'] ?? 0;
            $rows[] = [
                $cv->id,
                $cv->label,
                $status->isUsable()
                    ? sprintf('%s (%s, %s)', $status->label(), self::size((int) $status->size), $status->modifiedAt?->format('d/m/Y'))
                    : '<comment>' . $status->label() . '</comment>',
                $usedBy === [] ? '—' : implode(', ', $usedBy),
                $sent,
                $sent === 0 ? '—' : sprintf('%d/%d', $responded, $sent),
            ];
        }

        $io->table(['Identifiant', 'Libellé', 'Fichier', 'Profils de recherche', 'Envoyées', 'Réponses'], $rows);
        $io->writeln('<info>Réponses</info> : candidatures envoyées ayant reçu un entretien, un test, une proposition ou un refus. Échantillons petits : à lire avec prudence.');
        $io->writeln('Le contenu des fichiers n\'est jamais lu ni transmis.');

        if ($problems > 0) {
            $io->warning(sprintf('%d version(s) de CV sans fichier utilisable.', $problems));
        }

        return Command::SUCCESS;
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? sprintf('%.1f Mo', $bytes / 1048576) : sprintf('%d Ko', max(1, intdiv($bytes, 1024)));
    }
}
