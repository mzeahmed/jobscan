<?php

declare(strict_types=1);

namespace App\Tests\Integration\Matching;

use App\DTO\ContractType;
use App\AI\Extraction\JobRequirements;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Console\Application;

final class MatchCommandsTest extends MatchingIntegrationTestCase
{
    public function testMatchWarnsWhenThereIsNoProfile(): void
    {
        $tester = $this->command('app:jobs:match');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Aucun profil candidat ou aucun profil de recherche actif', $tester->getDisplay());
    }

    public function testMatchFailsOnAnInvalidProfile(): void
    {
        $this->writeProfile("skils: [PHP]\n");

        $tester = $this->command('app:jobs:match');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('candidate.yaml', $tester->getDisplay());
    }

    public function testMatchComputesThenOnlyRecomputesWhatIsStale(): void
    {
        $this->writeProfile();
        $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $this->persistAnalyzedJob('Autre offre', $this->backendRequirements(), url: 'https://jobs.example/2');
        $this->persistAnalyzedJob('Sans exigences', null, url: 'https://jobs.example/3');
        $this->entityManager->clear();

        $first = $this->command('app:jobs:match');
        self::assertSame(Command::SUCCESS, $first->execute([]));
        self::assertMatchesRegularExpression('/Offres avec exigences extraites\s+2/', $first->getDisplay());
        self::assertMatchesRegularExpression('/Résultats calculés\s+4/', $first->getDisplay());
        self::assertSame(4, $this->matches->count([]));

        $second = $this->command('app:jobs:match');
        $second->execute([]);
        self::assertMatchesRegularExpression('/Résultats calculés\s+0/', $second->getDisplay());
        self::assertMatchesRegularExpression('/Résultats déjà à jour\s+4/', $second->getDisplay());

        $forced = $this->command('app:jobs:match');
        $forced->execute(['--all' => true]);
        self::assertMatchesRegularExpression('/Résultats calculés\s+4/', $forced->getDisplay());
        self::assertSame(4, $this->matches->count([]));
        self::assertSame(0, $this->llm->calls);
    }

    public function testMatchExplainsWhenNoJobHasRequirements(): void
    {
        $this->writeProfile();
        $this->persistAnalyzedJob();

        $tester = $this->command('app:jobs:match');
        $tester->execute([]);

        self::assertStringContainsString('Aucune offre n\'a d\'exigences extraites', $tester->getDisplay());
    }

    public function testMatchRemovesTheResultsOfInactiveProfiles(): void
    {
        $this->writeProfile();
        $this->persistAnalyzedJob(requirements: $this->backendRequirements());
        $this->entityManager->clear();
        $this->command('app:jobs:match')->execute([]);
        self::assertSame(2, $this->matches->count([]));

        $this->writeProfile(self::CANDIDATE, str_replace("  devops:\n", "  devops:\n    enabled: false\n", self::SEARCH_PROFILES));
        $this->matcher()->reset();
        $tester = $this->command('app:jobs:match');
        $tester->execute([]);

        self::assertMatchesRegularExpression('/Résultats supprimés \(profils inactifs\)\s+1/', $tester->getDisplay());
        self::assertSame(1, $this->matches->count([]));
    }

    public function testPrioritiesRefreshWithoutRedoingTheMatching(): void
    {
        $this->writeProfile();
        $this->persistAnalyzedJob(requirements: $this->backendRequirements(), publishedAt: $this->now->modify('-2 hours'));
        $this->entityManager->clear();
        $this->command('app:jobs:match')->execute([]);

        $tester = $this->command('app:jobs:match');

        self::assertSame(Command::SUCCESS, $tester->execute(['--priorities' => true]));
        self::assertStringContainsString('rafraîchies pour 2 résultat(s)', $tester->getDisplay());
        self::assertSame(2, $this->matches->count([]));
    }

    public function testPriorityListsTheBestJobsWithTheirProfile(): void
    {
        $this->writeProfile();
        $this->persistAnalyzedJob('Développeur back-end PHP', $this->backendRequirements(), $this->now->modify('-2 hours'), url: 'https://jobs.example/a');
        $this->persistAnalyzedJob('Développeur COBOL', new JobRequirements(requiredSkills: ['COBOL', 'Fortran'], contractTypes: [ContractType::Cdi]), $this->now, url: 'https://jobs.example/b');
        $this->entityManager->clear();
        $this->command('app:jobs:match')->execute([]);

        $tester = $this->command('app:jobs:priority');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Développeur back-end PHP', $display);
        self::assertStringContainsString('backend', $display);
        self::assertStringNotContainsString('COBOL', $display, 'Une offre non compatible n\'est pas listée par défaut.');

        $all = $this->command('app:jobs:priority');
        $all->execute(['--all' => true]);
        self::assertStringContainsString('COBOL', $all->getDisplay());
    }

    public function testPriorityRejectsAnInvalidLimitAndSaysWhenThereIsNothing(): void
    {
        self::assertSame(Command::INVALID, $this->command('app:jobs:priority')->execute(['--limit' => '0']));

        $tester = $this->command('app:jobs:priority');
        $tester->execute([]);
        self::assertStringContainsString('Aucune offre à afficher', $tester->getDisplay());
    }

    public function testExplainShowsEveryPartOfTheReasoning(): void
    {
        $this->writeProfile();
        $job = $this->persistAnalyzedJob(
            'Développeur back-end PHP',
            new JobRequirements(
                requiredSkills: ['PHP', 'Kubernetes'],
                niceSkills: ['Docker'],
                seniority: \App\DTO\Seniority::Senior,
                minYearsExperience: 4,
                contractTypes: [ContractType::Cdi],
                warnings: ['Compétence « X » absente de l\'annonce, écartée.'],
            ),
            $this->now->modify('-3 hours'),
        );
        $this->entityManager->clear();

        $tester = $this->command('app:jobs:explain');
        self::assertSame(Command::SUCCESS, $tester->execute(['job' => (string) $job->getId(), '--recompute' => true]));
        $display = $tester->getDisplay();

        foreach ([
            'Développeur back-end PHP',
            '3 h',
            'Profil « backend »',
            'Compatibilité',
            'Fraîcheur',
            'Priorité',
            'Couverture de l\'information',
            'required_skills',
            '1/2 compétences exigées couvertes',
            'Compétences correspondantes',
            'Compétences exigées manquantes',
            'Kubernetes',
            'Informations inconnues',
            'À mettre en avant',
            'Compétence « X » absente',
        ] as $expected) {
            self::assertStringContainsString($expected, $display, $expected);
        }
    }

    public function testExplainShowsTheEliminationReason(): void
    {
        $this->writeProfile(self::CANDIDATE, "search_profiles:\n  backend:\n    titles: [\"Développeur back-end\"]\n    excluded_keywords: [alternance]\n");
        $job = $this->persistAnalyzedJob('Développeur back-end PHP', $this->backendRequirements(), description: 'Poste en alternance');
        $this->entityManager->clear();

        $tester = $this->command('app:jobs:explain');
        $tester->execute(['job' => (string) $job->getId(), '--recompute' => true]);

        self::assertStringContainsString('éliminée', $tester->getDisplay());
        self::assertStringContainsString('Mot-clé exclu « alternance »', $tester->getDisplay());
    }

    public function testExplainSaysWhyThereIsNoResult(): void
    {
        $job = $this->persistAnalyzedJob();
        $this->entityManager->clear();

        $tester = $this->command('app:jobs:explain');
        self::assertSame(Command::SUCCESS, $tester->execute(['job' => (string) $job->getId()]));
        self::assertStringContainsString('pas encore d\'exigences extraites', $tester->getDisplay());
    }

    public function testExplainFailsOnAnUnknownJob(): void
    {
        self::assertSame(Command::FAILURE, $this->command('app:jobs:explain')->execute(['job' => '9999']));
        self::assertSame(Command::FAILURE, $this->command('app:jobs:explain')->execute(['job' => 'abc']));
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application(self::$kernel)->find($name));
    }
}
