<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Profile\ProfileLoader;
use PHPUnit\Framework\TestCase;
use App\Command\InitProfileCommand;
use App\Command\ValidateProfileCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ProfileCommandsTest extends TestCase
{
    private string $local;
    private string $examples;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/jobscan-profile-cmd-' . bin2hex(random_bytes(6));
        $this->local = $base . '/local';
        $this->examples = $base . '/examples';
        mkdir($this->examples, 0o700, true);
        file_put_contents($this->examples . '/candidate.example.yaml', "skills: [PHP]\n");
        file_put_contents($this->examples . '/search_profiles.example.yaml', "search_profiles:\n  exemple:\n    keywords: [php]\n");
    }

    protected function tearDown(): void
    {
        foreach ([$this->local, $this->examples] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        rmdir(dirname($this->local));
    }

    public function testInitCreatesPrivateFilesFromTheExamplesAndNeverOverwrites(): void
    {
        $tester = new CommandTester($this->init());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertFileExists($this->local . '/candidate.yaml');
        self::assertFileExists($this->local . '/search_profiles.yaml');
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->local . '/candidate.yaml')), -4), 'Lisible par le seul propriétaire.');
        self::assertStringContainsString('EXEMPLES fictifs', $tester->getDisplay());

        file_put_contents($this->local . '/candidate.yaml', "skills: [MesCompetences]\n");
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("skills: [MesCompetences]\n", file_get_contents($this->local . '/candidate.yaml'), 'Un fichier existant n\'est jamais écrasé.');
        self::assertStringContainsString('Aucun fichier créé', $tester->getDisplay());
    }

    public function testInitCreatesOnlyTheMissingFile(): void
    {
        mkdir($this->local, 0o700, true);
        file_put_contents($this->local . '/candidate.yaml', "skills: [Mien]\n");

        $tester = new CommandTester($this->init());
        $tester->execute([]);

        self::assertSame("skills: [Mien]\n", file_get_contents($this->local . '/candidate.yaml'));
        self::assertFileExists($this->local . '/search_profiles.yaml');
    }

    public function testValidateWarnsWhenThereIsNoProfile(): void
    {
        $tester = new CommandTester($this->validate());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Aucun profil trouvé', $tester->getDisplay());
        self::assertStringContainsString('app:profile:init', $tester->getDisplay());
    }

    public function testValidateSummarisesWithoutRevealingPersonalData(): void
    {
        mkdir($this->local, 0o700, true);
        file_put_contents($this->local . '/candidate.yaml', <<<'YAML'
            identity:
              full_name: Jeanne Confidentielle
              email: jeanne.confidentielle@example.com
              phone: "+33 6 12 34 56 78"
            skills: [PHP, Symfony]
            experiences:
              - company: Entreprise Secrète
                title: Poste
                start: "2020-01"
                achievements: [Réalisation privée 42%]
            cvs:
              - {id: backend, label: CV, path: /home/jeanne/cv-prive.pdf}
            YAML);
        file_put_contents($this->local . '/search_profiles.yaml', "search_profiles:\n  backend:\n    name: Backend\n    primary_skills: [PHP]\n    cv: backend\n");

        $tester = new CommandTester($this->validate());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Profil valide.', $display);
        self::assertStringContainsString('Backend', $display);
        foreach (['Jeanne', 'confidentielle', '12 34 56', 'Secrète', 'Réalisation privée', 'cv-prive'] as $secret) {
            self::assertStringNotContainsString($secret, $display, 'Le contenu du profil ne doit jamais être affiché.');
        }
    }

    public function testValidateFailsOnAnInvalidProfile(): void
    {
        mkdir($this->local, 0o700, true);
        file_put_contents($this->local . '/candidate.yaml', "skils: [PHP]\n");

        $tester = new CommandTester($this->validate());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('candidate.yaml', $tester->getDisplay());
    }

    public function testValidateFlagsFilesIdenticalToTheExamples(): void
    {
        new CommandTester($this->init())->execute([]);

        $tester = new CommandTester($this->validate());
        $tester->execute([]);

        self::assertStringContainsString('identique à l\'exemple', $tester->getDisplay());
        self::assertStringContainsString('fictives', $tester->getDisplay());
    }

    private function loader(): ProfileLoader
    {
        return new ProfileLoader($this->local, ['required_skills' => 10], 50);
    }

    private function init(): InitProfileCommand
    {
        return new InitProfileCommand($this->loader(), $this->examples);
    }

    private function validate(): ValidateProfileCommand
    {
        return new ValidateProfileCommand($this->loader(), $this->examples);
    }
}
