<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\JobRepository;
use App\Tracking\ApplicationChannel;
use App\Tracking\ApplicationManager;
use Doctrine\ORM\EntityManagerInterface;
use App\Tracking\InvalidApplicationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:applications:track',
    description: 'Ouvre le suivi d\'une candidature pour une offre (brouillon ; rien n\'est envoyé).',
)]
final class ApplicationTrackCommand extends Command
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
            ->addArgument('job', InputArgument::REQUIRED, "Identifiant de l'offre (voir app:jobs:priority).")
            ->addOption('search-profile', null, InputOption::VALUE_REQUIRED, 'Profil de recherche utilisé.')
            ->addOption('cv', null, InputOption::VALUE_REQUIRED, 'Version de CV (déclarée dans candidate.yaml).')
            ->addOption('recruiter', null, InputOption::VALUE_REQUIRED, 'Nom du recruteur (saisi par vous).')
            ->addOption('contact', null, InputOption::VALUE_REQUIRED, 'Contact du recruteur (saisi par vous).')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Canal : ' . implode(', ', array_map(static fn (ApplicationChannel $c): string => $c->value, ApplicationChannel::cases())) . '.')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Note libre.');
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

        $channelValue = ApplicationCommandSupport::optionalString($input->getOption('channel'));
        $channel = $channelValue === null ? null : ApplicationChannel::tryFrom($channelValue);
        if ($channelValue !== null && $channel === null) {
            $io->error(sprintf('Canal inconnu : « %s ».', $channelValue));

            return Command::INVALID;
        }

        try {
            $application = $this->manager->start(
                $job,
                new \DateTimeImmutable(),
                ApplicationCommandSupport::optionalString($input->getOption('search-profile')),
                ApplicationCommandSupport::optionalString($input->getOption('cv')),
            );
        } catch (InvalidApplicationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $application->setRecruiterName(ApplicationCommandSupport::optionalString($input->getOption('recruiter')));
        $application->setRecruiterContact(ApplicationCommandSupport::optionalString($input->getOption('contact')));
        $application->setChannel($channel);
        $application->setNotes(ApplicationCommandSupport::optionalString($input->getOption('note')));
        $this->entityManager->flush();

        $io->success(sprintf('Candidature n°%d ouverte (brouillon) pour « %s ». L\'offre est désormais protégée de la purge.', (int) $application->getId(), $job->getTitle()));

        return Command::SUCCESS;
    }
}
