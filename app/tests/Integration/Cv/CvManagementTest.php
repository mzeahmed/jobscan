<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cv;

use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;
use App\Repository\ApplicationRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use App\Tests\Integration\Notification\NotificationIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application as ConsoleApplication;

final class CvManagementTest extends NotificationIntegrationTestCase
{
    private string $cvFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cvFile = sys_get_temp_dir() . '/jobscan-cv-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($this->cvFile, str_repeat('x', 4096));
        $this->writeProfile(
            "skills: [PHP, Symfony, Docker]\nexperiences:\n  - {company: Exemple SAS, title: Développeur, start: \"2015-01\", skills: [PHP]}\nremote_modes: [remote, hybrid]\ncontracts: [cdi]\ncvs:\n  - {id: backend-php, label: CV Backend PHP, path: {$this->cvFile}}\n  - {id: devops, label: CV DevOps, path: /introuvable/cv.pdf}\n  - {id: spare, label: CV inutilisé}\n",
            self::BACKEND_ONLY,
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->cvFile);
        parent::tearDown();
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new ConsoleApplication(self::$kernel)->find($name));
    }

    public function testRecommendsTheCvOfTheMostRelevantProfile(): void
    {
        $job = $this->eligibleJob();

        $tester = $this->command('app:cv:recommend');
        self::assertSame(Command::SUCCESS, $tester->execute(['job' => (string) $job->getId()]));

        self::assertStringContainsString('CV Backend PHP', $tester->getDisplay());
        self::assertStringContainsString('Backend PHP / Symfony', $tester->getDisplay());
        self::assertStringNotContainsString('introuvable', $tester->getDisplay());
    }

    public function testNoRecommendationWhenNoProfileFindsTheJobCompatible(): void
    {
        $job = $this->persistAnalyzedJob('Développeur COBOL', null, null, url: 'https://jobs.example/cobol');

        $tester = $this->command('app:cv:recommend');
        $tester->execute(['job' => (string) $job->getId()]);

        self::assertStringContainsString('Pas de recommandation', $tester->getDisplay());
        self::assertStringContainsString('compatible', $tester->getDisplay());
    }

    public function testNoRecommendationWhenTheProfileHasNoCv(): void
    {
        $this->writeProfile(self::CANDIDATE_WITH_CV, "search_profiles:\n  backend:\n    titles: [\"Développeur back-end\"]\n    primary_skills: [PHP]\n    min_match_score: 60\n");
        $this->jobMatcher()->reset();
        $job = $this->eligibleJob();

        $tester = $this->command('app:cv:recommend');
        $tester->execute(['job' => (string) $job->getId()]);

        self::assertStringContainsString('pas de CV associé', $tester->getDisplay());
        self::assertStringNotContainsString('CV recommandé', $tester->getDisplay());
    }

    public function testWarnsWhenTheRecommendedFileIsMissing(): void
    {
        $this->writeProfile(
            "skills: [PHP, Symfony, Docker]\nexperiences:\n  - {company: Exemple SAS, title: Développeur, start: \"2015-01\", skills: [PHP]}\nremote_modes: [remote]\ncontracts: [cdi]\ncvs:\n  - {id: backend-php, label: CV Backend PHP, path: /introuvable/cv.pdf}\n",
            self::BACKEND_ONLY,
        );
        $this->jobMatcher()->reset();
        $job = $this->eligibleJob();

        $tester = $this->command('app:cv:recommend');
        $tester->execute(['job' => (string) $job->getId()]);

        self::assertStringContainsString('fichier introuvable', $tester->getDisplay());
    }

    public function testListShowsFilesProfilesAndResponseStatistics(): void
    {
        $manager = self::getContainer()->get(ApplicationManager::class);
        $first = $manager->start($this->eligibleJob(url: 'https://jobs.example/1'), $this->now, null, 'backend-php');
        $second = $manager->start($this->eligibleJob(url: 'https://jobs.example/2'), $this->now, null, 'backend-php');
        $draft = $manager->start($this->eligibleJob(url: 'https://jobs.example/3'), $this->now, null, 'backend-php');
        $manager->changeStatus($first, ApplicationStatus::Applied, $this->now->modify('+1 day'));
        $manager->changeStatus($first, ApplicationStatus::Interview, $this->now->modify('+3 days'));
        $manager->changeStatus($second, ApplicationStatus::Applied, $this->now->modify('+1 day'));
        $this->entityManager->flush();
        self::assertSame(ApplicationStatus::Draft, $draft->getStatus());

        $tester = $this->command('app:cv:list');
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();

        self::assertStringContainsString('fichier présent (4 Ko', $display);
        self::assertStringContainsString('fichier introuvable', $display);
        self::assertStringContainsString('chemin non renseigné', $display);
        self::assertMatchesRegularExpression('/backend-php CV Backend PHP .*backend 2 1\/2/', (string) preg_replace('/\s+/', ' ', $display));
        self::assertSame(['backend-php' => ['sent' => 2, 'responded' => 1]], self::getContainer()->get(ApplicationRepository::class)->statsByCv(), 'Les brouillons ne comptent pas.');
        self::assertStringContainsString('jamais lu', $display);
    }

    public function testListWithoutCvsExplainsWhatToDo(): void
    {
        $this->writeProfile("skills: [PHP]\nexperiences:\n  - {company: Exemple SAS, title: Développeur, start: \"2015-01\", skills: [PHP]}\n", "search_profiles:\n  backend:\n    titles: [Développeur]\n    primary_skills: [PHP]\n");
        $this->jobMatcher()->reset();

        $tester = $this->command('app:cv:list');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Aucune version de CV', $tester->getDisplay());
    }

    public function testChangeAndRemoveTheCvOfAnApplication(): void
    {
        $application = self::getContainer()->get(ApplicationManager::class)->start($this->eligibleJob(), $this->now);
        $this->entityManager->flush();
        $id = (string) $application->getId();

        self::assertSame(Command::SUCCESS, $this->command('app:applications:cv')->execute(['application' => $id, 'cv' => 'devops']));
        $this->entityManager->refresh($application);
        self::assertSame('CV DevOps', $application->getCvVersionLabel());

        self::assertSame(Command::FAILURE, $this->command('app:applications:cv')->execute(['application' => $id, 'cv' => 'inconnu']));
        $this->entityManager->refresh($application);
        self::assertSame('devops', $application->getCvVersionId(), 'Un CV inconnu ne change rien.');

        self::assertSame(Command::SUCCESS, $this->command('app:applications:cv')->execute(['application' => $id, 'cv' => 'none']));
        $this->entityManager->refresh($application);
        self::assertNull($application->getCvVersionId());
        self::assertNull($application->getCvVersionLabel());

        self::assertSame(Command::FAILURE, $this->command('app:applications:cv')->execute(['application' => '999', 'cv' => 'devops']));
    }

    public function testTrackingSuggestsTheRecommendedCvWithoutApplyingIt(): void
    {
        $job = $this->eligibleJob();

        $tester = $this->command('app:applications:track');
        $tester->execute(['job' => (string) $job->getId()]);

        self::assertStringContainsString('Recommandé : « CV Backend PHP »', $tester->getDisplay());
        self::assertNull(self::getContainer()->get(ApplicationRepository::class)->find(1)->getCvVersionId(), 'Une recommandation n\'est pas appliquée sans demande.');
    }
}
