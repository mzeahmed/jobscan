<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\JobRepository;
use App\Notification\MatchNotifier;
use App\Notification\DigestNotifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Envoie le récapitulatif périodique Telegram et rattrape les notifications immédiates manquées.
 *
 * À planifier (cron, par exemple une fois par jour) : Jobscan n'envoie un récapitulatif que lorsque cette
 * commande est lancée.
 */
#[AsCommand(
    name: 'app:notifications:digest',
    description: 'Envoie le récapitulatif Telegram des offres secondaires et rattrape les notifications immédiates manquées.',
)]
final class NotifyDigestCommand extends Command
{
    private const int CATCH_UP_LIMIT = 10;

    public function __construct(
        private readonly MatchNotifier $matchNotifier,
        private readonly DigestNotifier $digestNotifier,
        private readonly JobRepository $jobRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-items', null, InputOption::VALUE_REQUIRED, 'Nombre maximal d\'offres du récapitulatif (défaut : app.notifications.digest_max_items).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche le récapitulatif sans l\'envoyer ni marquer les offres.')
            ->addOption('no-catchup', null, InputOption::VALUE_NONE, 'Ne rattrape pas les notifications immédiates manquées.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('JOBSCAN — Récapitulatif des notifications');

        if (!$this->matchNotifier->isActive()) {
            $io->note("Aucun profil candidat ou profil de recherche actif (ou profil invalide) : les notifications suivent le score historique, il n'y a pas de récapitulatif.");

            return Command::SUCCESS;
        }

        $maxItems = null;
        if ($input->getOption('max-items') !== null) {
            $maxItems = filter_var($input->getOption('max-items'), FILTER_VALIDATE_INT);
            if (!is_int($maxItems) || $maxItems < 1) {
                $io->error('--max-items doit être un entier positif.');

                return Command::INVALID;
            }
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $now = new \DateTimeImmutable();

        $caughtUp = 0;
        if (!$dryRun && !(bool) $input->getOption('no-catchup')) {
            $caughtUp = $this->matchNotifier->catchUp($now, self::CATCH_UP_LIMIT);
            $this->jobRepository->flush();
        }

        $result = $this->digestNotifier->send($now, $dryRun, $maxItems);
        $this->jobRepository->flush();

        $io->table(['Indicateur', 'Total'], [
            ['Notifications immédiates rattrapées', $caughtUp],
            ['Offres candidates au récapitulatif', $result->candidates],
            ['Offres reprises dans le message', $result->included],
            ['Offres laissées pour le prochain récapitulatif', $result->remaining()],
        ]);

        if ($result->candidates === 0) {
            $io->note('Aucune offre à récapituler.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->section('Message (aperçu, non envoyé)');
            $io->writeln(strip_tags($result->text));

            return Command::SUCCESS;
        }

        if (!$result->sent) {
            $io->warning('Le récapitulatif n\'a pas pu être envoyé : aucune offre n\'a été marquée, il sera retenté au prochain lancement.');

            return Command::FAILURE;
        }

        $io->success(sprintf('Récapitulatif envoyé : %d offre(s).', $result->included));

        return Command::SUCCESS;
    }
}
