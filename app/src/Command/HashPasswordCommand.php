<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

#[AsCommand(
    name: 'app:ui:hash-password',
    description: 'Calcule le hash du mot de passe de l\'interface web (à placer dans JOBSCAN_UI_PASSWORD_HASH).',
)]
final class HashPasswordCommand extends Command
{
    private const int MIN_LENGTH = 12;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ask = static function (string $label) use ($io): string {
            $question = new Question($label);
            $question->setHidden(true);
            $question->setHiddenFallback(false);

            return (string) $io->askQuestion($question);
        };

        $password = $ask('Mot de passe (saisie masquée)');
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $io->error(sprintf('Mot de passe trop court : %d caractères au minimum.', self::MIN_LENGTH));

            return Command::FAILURE;
        }

        if (!hash_equals($password, $ask('Confirmez le mot de passe'))) {
            $io->error('Les deux saisies diffèrent.');

            return Command::FAILURE;
        }

        $hash = new NativePasswordHasher()->hash($password);

        $io->success('Ajoutez cette ligne à app/.env.local (les apostrophes sont indispensables) :');
        $output->writeln("JOBSCAN_UI_PASSWORD_HASH='" . $hash . "'");

        return Command::SUCCESS;
    }
}
