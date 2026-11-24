<?php

declare(strict_types=1);

namespace App\Tests\Unit\Profile;

use App\DTO\ContractType;
use App\Profile\ProfileLoader;
use App\Profile\Model\RemotePreference;
use App\Profile\InvalidProfileException;
use PHPUnit\Framework\Attributes\DataProvider;

final class SearchProfilesTest extends ProfileTestCase
{
    private const string CANDIDATE = <<<'YAML'
        skills: [PHP, Symfony, Docker]
        excluded_skills: [Java]
        cvs:
          - {id: backend, label: CV Backend}
        YAML;

    public function testLoadsSeveralIndependentProfiles(): void
    {
        $set = $this->load(self::CANDIDATE, <<<'YAML'
            search_profiles:
              backend-php:
                name: Backend PHP
                keywords: [php, api]
                titles: ["Développeur back-end"]
                primary_skills: [PHP, Symfony]
                secondary_skills: [Docker]
                locations: [Marseille]
                contracts: [cdi]
                remote_modes: [remote]
                excluded_keywords: [stage]
                excluded_titles: ["Chef de projet"]
                excluded_skills: [Cobol]
                min_match_score: 65
                cv: backend
                weights:
                  required_skills: 60
              devops:
                enabled: false
                keywords: [devops]
            YAML);

        self::assertCount(2, $set->searchProfiles);
        [$backend, $devops] = $set->searchProfiles;

        self::assertSame('backend-php', $backend->id);
        self::assertSame('Backend PHP', $backend->name);
        self::assertTrue($backend->enabled);
        self::assertSame(['PHP', 'Symfony'], $backend->primarySkills);
        self::assertSame(['Docker'], $backend->secondarySkills);
        self::assertSame([ContractType::Cdi], $backend->contracts);
        self::assertSame([RemotePreference::Remote], $backend->remoteModes);
        self::assertSame(['stage'], $backend->excludedKeywords);
        self::assertSame(65, $backend->minMatchScore);
        self::assertSame('backend', $backend->cvId);
        self::assertSame(60, $backend->weights->weight('required_skills'));
        self::assertSame(15, $backend->weights->weight('nice_skills'), 'Poids absent : valeur par défaut.');

        self::assertSame('devops', $devops->name, 'Nom absent : repli sur l\'identifiant.');
        self::assertFalse($devops->enabled);
        self::assertSame(50, $devops->minMatchScore, 'Seuil absent : valeur par défaut.');
        self::assertSame(40, $devops->weights->weight('required_skills'));
        self::assertNull($devops->cvId);
        self::assertSame([], $devops->contracts);

        self::assertSame(['backend-php'], array_map(static fn ($p): string => $p->id, $set->enabledSearchProfiles()));
        self::assertSame($backend, $set->searchProfile('backend-php'));
        self::assertNull($set->searchProfile('inconnu'));
    }

    public function testTheDefaultMinimumScoreIsConfigurable(): void
    {
        $this->write(ProfileLoader::SEARCH_PROFILES_FILE, "search_profiles:\n  a:\n    keywords: [php]\n");

        self::assertSame(70, $this->loader(70)->load()->searchProfiles[0]->minMatchScore);
    }

    public function testAScalarIsAcceptedWhereAListIsExpected(): void
    {
        $set = $this->load('', "search_profiles:\n  a:\n    keywords: php\n    contracts: cdi\n    locations: Paris\n");

        self::assertSame(['php'], $set->searchProfiles[0]->keywords);
        self::assertSame([ContractType::Cdi], $set->searchProfiles[0]->contracts);
        self::assertSame(['Paris'], $set->searchProfiles[0]->locations);
    }

    public function testProfileIdsKeepTheirHyphens(): void
    {
        $set = $this->load('', "search_profiles:\n  full-stack-php-react:\n    keywords: [php]\n");

        self::assertSame('full-stack-php-react', $set->searchProfiles[0]->id);
    }

    public function testASkillTargetedButNotDeclaredIsAWarningNotAnAssumption(): void
    {
        $set = $this->load("skills: [PHP]\n", "search_profiles:\n  a:\n    primary_skills: [PHP, Go]\n");

        self::assertCount(1, $set->warnings);
        self::assertStringContainsString('« Go »', $set->warnings[0]);
        self::assertStringContainsString('pas comptée comme maîtrisée', $set->warnings[0]);
    }

    public function testWarnsAboutAnEnabledProfileThatTargetsNothing(): void
    {
        $set = $this->load(self::CANDIDATE, "search_profiles:\n  vide:\n    min_match_score: 50\n  inactif:\n    enabled: false\n");

        self::assertCount(1, $set->warnings, 'Seul le profil actif est signalé.');
        self::assertStringContainsString('vide', $set->warnings[0]);
    }

    public function testWarnsWhenProfilesExistWithoutACandidate(): void
    {
        $set = $this->load('', "search_profiles:\n  a:\n    keywords: [php]\n");

        self::assertNull($set->candidate);
        self::assertStringContainsString('candidate.yaml est absent', $set->warnings[0]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSearchProfiles(): iterable
    {
        yield 'identifiant en majuscules' => ["search_profiles:\n  Backend:\n    keywords: [php]\n", 'Identifiant'];
        yield 'clé inconnue' => ["search_profiles:\n  a:\n    keyword: [php]\n", 'keyword'];
        yield 'poids inconnu' => ["search_profiles:\n  a:\n    weights:\n      magic: 10\n", 'magic'];
        yield 'poids négatif' => ["search_profiles:\n  a:\n    weights:\n      title: -5\n", 'title'];
        yield 'seuil hors plage' => ["search_profiles:\n  a:\n    min_match_score: 150\n", 'min_match_score'];
        yield 'tous les poids à zéro' => [
            "search_profiles:\n  a:\n    weights:\n"
            . implode('', array_map(static fn (string $c): string => "      $c: 0\n", [
                'required_skills', 'nice_skills', 'title', 'location', 'remote', 'contract', 'salary', 'seniority', 'languages', 'availability',
            ])),
            'strictement positif',
        ];
        yield 'contrat inconnu' => ["search_profiles:\n  a:\n    contracts: [portage]\n", 'portage'];
        yield 'compétence ciblée et exclue' => ["search_profiles:\n  a:\n    primary_skills: [PHP]\n    excluded_skills: [php]\n", 'cible et exclut'];
        yield 'prioritaire et secondaire' => ["search_profiles:\n  a:\n    primary_skills: [PHP]\n    secondary_skills: [php]\n", 'prioritaire et secondaire'];
        yield 'compétence exclue par le candidat' => ["search_profiles:\n  a:\n    primary_skills: [Java]\n", 'exclue'];
        yield 'CV inconnu' => ["search_profiles:\n  a:\n    keywords: [php]\n    cv: inconnu\n", 'inconnu'];
        yield 'clé dupliquée' => ["search_profiles:\n  a:\n    keywords: [php]\n  a:\n    keywords: [go]\n", 'YAML invalide'];
    }

    #[DataProvider('invalidSearchProfiles')]
    public function testRejectsInvalidSearchProfilesNamingTheFile(string $yaml, string $expectedFragment): void
    {
        $this->write('candidate.yaml', self::CANDIDATE);
        $this->write('search_profiles.yaml', $yaml);

        try {
            $this->loader()->load();
            self::fail('Une InvalidProfileException était attendue.');
        } catch (InvalidProfileException $e) {
            self::assertStringContainsString('search_profiles.yaml', $e->getMessage());
            self::assertStringContainsString($expectedFragment, $e->getMessage());
        }
    }
}
