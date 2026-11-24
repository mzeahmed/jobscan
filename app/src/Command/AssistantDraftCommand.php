<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\JobRepository;
use App\Assistant\AssistantException;
use App\Assistant\ApplicationAssistant;
use App\Profile\InvalidProfileException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:assistant:draft',
    description: 'Prépare un brouillon de message de candidature à partir de vos faits déclarés (rien n\'est envoyé ni enregistré).',
)]
final class AssistantDraftCommand extends Command
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ApplicationAssistant $assistant,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('job', InputArgument::REQUIRED, "Identifiant de l'offre.")
            ->addOption('search-profile', null, InputOption::VALUE_REQUIRED, 'Profil de recherche à utiliser (défaut : le plus pertinent).')
            ->addOption('llm', null, InputOption::VALUE_NONE, 'Fait rédiger le texte par le LLM configuré (local, ou distant si vous l\'avez autorisé), puis le contrôle.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = \App\Tracking\InputReader::id($input->getArgument('job'));
        $job = $id === null ? null : $this->jobs->find($id);
        if ($job === null) {
            $io->error('Offre introuvable.');

            return Command::FAILURE;
        }

        try {
            $draft = $this->assistant->draft($job, \App\Tracking\InputReader::optionalString($input->getOption('search-profile')), (bool) $input->getOption('llm'));
        } catch (AssistantException | InvalidProfileException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title('Brouillon de candidature');
        $output->writeln('<comment>' . str_repeat('─', 60) . '</comment>');
        $output->writeln(OutputFormatterEscape::escape($draft->text()));
        $output->writeln('<comment>' . str_repeat('─', 60) . '</comment>');
        $io->section('À vérifier avant d\'envoyer');
        $io->listing(array_map(OutputFormatterEscape::escape(...), $draft->checks));

        return Command::SUCCESS;
    }
}
