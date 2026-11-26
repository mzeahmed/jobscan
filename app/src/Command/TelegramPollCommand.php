<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Log\LoggerInterface;
use App\Telegram\OffsetStore;
use App\Telegram\TelegramBot;
use App\Telegram\UpdateHandler;
use App\Telegram\UpdateAuthorizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\SignalableCommandInterface;

#[AsCommand(
    name: 'app:telegram:poll',
    description: 'Écoute Telegram : boutons des notifications et commandes (/offres, /candidatures). Aucun port exposé.',
)]
final class TelegramPollCommand extends Command implements SignalableCommandInterface
{
    private const int LONG_POLL_SECONDS = 25;
    private const int RETRY_DELAY_SECONDS = 5;

    private bool $stop = false;

    public function __construct(
        private readonly TelegramBot $bot,
        private readonly UpdateHandler $handler,
        private readonly UpdateAuthorizer $authorizer,
        private readonly OffsetStore $offsets,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Traite les mises à jour en attente puis s\'arrête (pour un cron).')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Durée maximale d\'écoute (0 : jusqu\'à Ctrl+C).', '0');
    }

    /** @return list<int> */
    #[\Override]
    public function getSubscribedSignals(): array
    {
        // Les signaux n'existent qu'avec l'extension pcntl : sans elle, la commande tourne, mais ne s'arrête proprement que par --max-seconds.
        return \defined('SIGINT') && \defined('SIGTERM') ? [\SIGINT, \SIGTERM] : [];
    }

    #[\Override]
    public function handleSignal(int $signal, int | false $previousExitCode = 0): int | false
    {
        $this->stop = true;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->authorizer->isEnabled()) {
            $io->error('TELEGRAM_CHAT_ID désigne un groupe ou un canal : les actions sont désactivées tant que TELEGRAM_ALLOWED_USER_IDS ne liste pas les utilisateurs autorisés (voir docs/TELEGRAM.md).');

            return Command::FAILURE;
        }

        $maxSeconds = filter_var($input->getOption('max-seconds'), FILTER_VALIDATE_INT);
        if (!is_int($maxSeconds) || $maxSeconds < 0) {
            $io->error('--max-seconds doit être un entier positif ou nul.');

            return Command::INVALID;
        }

        $once = (bool) $input->getOption('once');
        $deadline = $maxSeconds > 0 ? time() + $maxSeconds : null;
        $offset = $this->offsets->read();
        $handled = 0;

        while (!$this->stop) {
            $updates = $this->bot->getUpdates($offset, $once ? 0 : self::LONG_POLL_SECONDS);

            if ($updates === null) {
                if ($once) {
                    $io->error('Telegram injoignable ou refus de l\'appel (voir les journaux).');

                    return Command::FAILURE;
                }

                sleep(self::RETRY_DELAY_SECONDS);
            } else {
                foreach ($updates as $update) {
                    $this->process($update);
                    ++$handled;
                    $updateId = is_int($update['update_id'] ?? null) ? $update['update_id'] : null;
                    if ($updateId !== null) {
                        $offset = $updateId + 1;
                        $this->offsets->write($offset);
                    }
                }
            }

            if ($once || ($deadline !== null && time() >= $deadline)) {
                break;
            }
        }

        $io->success(sprintf('%d mise(s) à jour traitée(s).', $handled));

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $update */
    private function process(array $update): void
    {
        try {
            $this->handler->handle($update, new \DateTimeImmutable());
        } catch (\Throwable $e) {
            // Une mise à jour qui échoue ne doit ni arrêter l'écoute ni être rejouée en boucle.
            $this->logger->error('Mise à jour Telegram non traitée.', ['exception' => $e::class, 'error' => $e->getMessage()]);
        } finally {
            $this->entityManager->clear();
        }
    }
}
