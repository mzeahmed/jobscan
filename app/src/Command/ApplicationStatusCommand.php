<?php

declare(strict_types=1);

namespace App\Command;

use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\ApplicationRepository;
use App\Tracking\InvalidApplicationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:applications:status',
    description: 'Change le statut d\'une candidature (consigne ce que vous avez fait ; rien n\'est envoyé).',
)]
final class ApplicationStatusCommand extends Command
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
            ->addArgument('status', InputArgument::REQUIRED, 'Nouveau statut : ' . implode(', ', array_map(static fn (ApplicationStatus $s): string => $s->value, ApplicationStatus::cases())) . '.')
            ->addOption('at', null, InputOption::VALUE_REQUIRED, 'Date réelle du changement (défaut : maintenant).')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Note consignée dans l\'historique.')
            ->addOption('follow-up', null, InputOption::VALUE_REQUIRED, 'Date de relance prévue.')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Message que vous avez envoyé (conservé tel quel).');
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

        $status = ApplicationStatus::tryFrom((string) $input->getArgument('status'));
        if ($status === null) {
            $io->error(sprintf('Statut inconnu : « %s ».', (string) $input->getArgument('status')));

            return Command::INVALID;
        }

        $now = new \DateTimeImmutable();
        try {
            $at = \App\Tracking\InputReader::date($input->getOption('at'), $now);
            $followUp = $input->getOption('follow-up') === null ? null : \App\Tracking\InputReader::anyDate((string) $input->getOption('follow-up'));
            $from = $application->getStatus();
            $this->manager->changeStatus($application, $status, $at, \App\Tracking\InputReader::optionalString($input->getOption('note')));
            if ($followUp !== null) {
                $application->setFollowUpAt($followUp);
            }
        } catch (\InvalidArgumentException | InvalidApplicationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $message = \App\Tracking\InputReader::optionalString($input->getOption('message'));
        if ($message !== null) {
            $application->setMessageSent($message);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Candidature n°%d : %s → %s.', (int) $application->getId(), $from->label(), $status->label()));

        return Command::SUCCESS;
    }
}
