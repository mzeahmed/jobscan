<?php

declare(strict_types=1);

namespace App\Command;

use App\Tracking\JobUserState;
use App\Repository\JobRepository;
use App\Tracking\ApplicationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:jobs:decide',
    description: 'Sauvegarde, ignore ou archive une offre (ou retire la décision avec « none »). Une offre décidée échappe à la purge.',
)]
final class JobDecideCommand extends Command
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ApplicationManager $manager,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('job', InputArgument::REQUIRED, "Identifiant de l'offre.")
            ->addArgument('state', InputArgument::REQUIRED, 'saved, ignored, archived ou none.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = ApplicationCommandSupport::id($input->getArgument('job'));
        $job = $id === null ? null : $this->jobs->find($id);
        if ($job === null) {
            $io->error('Offre introuvable.');

            return Command::FAILURE;
        }

        $value = (string) $input->getArgument('state');
        $state = $value === 'none' ? null : JobUserState::tryFrom($value);
        if ($value !== 'none' && $state === null) {
            $io->error(sprintf('État inconnu : « %s » (saved, ignored, archived, none).', $value));

            return Command::INVALID;
        }

        $this->manager->decide($job, $state, new \DateTimeImmutable());
        $this->entityManager->flush();

        $io->success($state === null ? 'Décision retirée.' : sprintf('Offre « %s » : %s.', $job->getTitle(), $state->label()));

        return Command::SUCCESS;
    }
}
