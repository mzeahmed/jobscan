<?php

declare(strict_types=1);

namespace App\Command;

use App\Tracking\ApplicationManager;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\ApplicationRepository;
use App\Tracking\InvalidApplicationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:applications:cv',
    description: 'Associe une version de CV à une candidature (« none » pour retirer l\'association).',
)]
final class ApplicationCvCommand extends Command
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ApplicationManager $manager,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('application', InputArgument::REQUIRED, 'Identifiant de la candidature.')
            ->addArgument('cv', InputArgument::REQUIRED, 'Identifiant de la version de CV, ou none.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = \App\Tracking\InputReader::id($input->getArgument('application'));
        $application = $id === null ? null : $this->applications->find($id);
        if ($application === null) {
            $io->error('Candidature introuvable.');

            return Command::FAILURE;
        }

        $cv = (string) $input->getArgument('cv');
        try {
            if ($cv === 'none') {
                $application->setCvVersion(null, null);
            } else {
                $this->manager->attachCv($application, $cv);
            }
        } catch (InvalidApplicationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $this->entityManager->flush();

        $io->success($cv === 'none' ? 'CV retiré de la candidature.' : sprintf('CV « %s » associé à la candidature n°%d.', (string) $application->getCvVersionLabel(), (int) $application->getId()));

        return Command::SUCCESS;
    }
}
