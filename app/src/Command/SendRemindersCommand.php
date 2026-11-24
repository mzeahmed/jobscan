<?php

declare(strict_types=1);

namespace App\Command;

use App\Tracking\InputReader;
use App\Reminder\FollowUpReminder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:reminders:send',
    description: 'Envoie par Telegram un rappel pour chaque relance planifiée dont la date est atteinte (une seule fois par date).',
)]
final class SendRemindersCommand extends Command
{
    public function __construct(
        private readonly FollowUpReminder $reminder,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de rappels par exécution.', '10')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les rappels sans les envoyer ni les marquer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Rappels de relance');

        $limit = InputReader::id($input->getOption('limit'));
        if ($limit === null) {
            $io->error('--limit doit être un entier positif.');

            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->reminder->send(new \DateTimeImmutable(), $dryRun, $limit);
        $this->entityManager->flush();

        if ($result->due === 0) {
            $io->success('Aucune relance à rappeler.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            foreach ($result->previews as $preview) {
                $io->writeln(OutputFormatterEscape::escape(strip_tags($preview)));
                $io->newLine();
            }
            $io->note(sprintf('%d rappel(s) — aperçu, non envoyé.', $result->due));

            return Command::SUCCESS;
        }

        $io->definitionList(['Rappels envoyés' => $result->sent], ['Échecs (retentés au prochain lancement)' => $result->failed()]);

        if ($result->failed() > 0) {
            $io->error('Telegram n\'a pas reçu tous les rappels.');

            return Command::FAILURE;
        }

        $io->success(sprintf('%d rappel(s) envoyé(s).', $result->sent));

        return Command::SUCCESS;
    }
}
