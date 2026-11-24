<?php

declare(strict_types=1);

namespace App\Command;

use App\Cv\CvInspector;
use App\Cv\CvRecommender;
use App\Repository\JobRepository;
use App\Profile\InvalidProfileException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:cv:recommend',
    description: 'Recommande la version de CV à utiliser pour une offre (d\'après son profil de recherche le plus pertinent).',
)]
final class CvRecommendCommand extends Command
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly CvRecommender $recommender,
        private readonly CvInspector $inspector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, "Identifiant de l'offre.");
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

        try {
            $recommendation = $this->recommender->recommend($job);
        } catch (InvalidProfileException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(sprintf('<info>%s</info>', $job->getTitle()));
        if ($recommendation->cv === null) {
            $io->note('Pas de recommandation. ' . $recommendation->reason);

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('CV recommandé : <info>%s</info> (%s)', $recommendation->cv->label, $recommendation->cv->id));
        $io->writeln($recommendation->reason);
        $file = $this->inspector->inspect($recommendation->cv);
        if (!$file->isUsable()) {
            $io->warning('Attention : ' . $file->label() . '.');
        }

        return Command::SUCCESS;
    }
}
