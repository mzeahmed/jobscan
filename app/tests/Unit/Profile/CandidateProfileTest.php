<?php

declare(strict_types=1);

namespace App\Tests\Unit\Profile;

use App\DTO\ContractType;
use App\Profile\Model\Mobility;
use App\Profile\Model\SkillLevel;
use App\Profile\Model\SalaryPeriod;
use App\Profile\Model\RemotePreference;
use App\Profile\InvalidProfileException;
use PHPUnit\Framework\Attributes\DataProvider;

final class CandidateProfileTest extends ProfileTestCase
{
    public function testMissingFilesMeanNoProfileNotAnError(): void
    {
        $set = $this->loader()->load();

        self::assertNull($set->candidate);
        self::assertSame([], $set->searchProfiles);
        self::assertSame([], $set->warnings);
    }

    public function testAnEmptyFileIsAnEmptyProfile(): void
    {
        foreach (["# rien pour l'instant\n", ''] as $content) {
            $this->write('candidate.yaml', $content);
            $candidate = $this->loader()->load()->candidate;

            self::assertNotNull($candidate, 'Un fichier présent mais vide est un profil vide, pas un profil absent.');
            self::assertSame([], $candidate->skills);
        }
    }

    public function testLoadsEveryDocumentedField(): void
    {
        $candidate = $this->load(<<<'YAML'
            identity:
              full_name: Alex Test
              headline: Développeur
              email: alex@example.com
              phone: "+33 6 00 00 00 00"
            target_titles: [Développeur PHP]
            skills:
              - name: PHP
                level: expert
                years: 8
              - Symfony
            excluded_skills: [COBOL]
            experiences:
              - company: Exemple SAS
                title: Dev
                start: "2019-03"
                end: "2022-06"
                summary: Plateforme
                achievements: [Migration]
                skills: [PHP]
            projects:
              - name: Outil
                summary: CLI
                url: https://example.com
                skills: [PHP]
            achievements: [Formations internes]
            certifications:
              - name: Certif
                issuer: Organisme
                year: 2022
              - Autre certification
            languages:
              - name: Français
                level: natif
              - Anglais
            location:
              cities: [Marseille]
              mobility: regional
              max_distance_km: 50
            remote_modes: [remote, hybrid]
            contracts: [cdi, freelance]
            salary:
              - contract: freelance
                period: daily
                min: 500
              - contract: cdi
                period: annual
                min: 45000
                max: 55000
            availability:
              from: "2026-01-05"
              notice_days: 30
              comment: Après préavis
            cvs:
              - id: backend-php
                label: CV Backend
                path: ~/cv.pdf
            YAML)->candidate;

        self::assertNotNull($candidate);
        self::assertSame('Alex Test', $candidate->fullName);
        self::assertSame('alex@example.com', $candidate->email);
        self::assertSame(['Développeur PHP'], $candidate->targetTitles);
        self::assertSame(SkillLevel::Expert, $candidate->skills[0]->level);
        self::assertSame(8.0, $candidate->skills[0]->yearsOfExperience);
        self::assertSame(['COBOL'], $candidate->excludedSkills);
        self::assertSame('2019-03', $candidate->experiences[0]->startDate);
        self::assertSame(['Migration'], $candidate->experiences[0]->achievements);
        self::assertSame('https://example.com', $candidate->projects[0]->url);
        self::assertSame(2022, $candidate->certifications[0]->year);
        self::assertSame('Autre certification', $candidate->certifications[1]->name);
        self::assertNull($candidate->certifications[1]->year);
        self::assertSame('natif', $candidate->languages[0]->level);
        self::assertNull($candidate->languages[1]->level);
        self::assertSame(Mobility::Regional, $candidate->location->mobility);
        self::assertSame(50, $candidate->location->maxDistanceKm);
        self::assertSame([RemotePreference::Remote, RemotePreference::Hybrid], $candidate->remoteModes);
        self::assertSame([ContractType::Cdi, ContractType::Freelance], $candidate->contracts);
        self::assertSame(SalaryPeriod::Daily, $candidate->salaryExpectations[0]->period);
        self::assertSame(ContractType::Freelance, $candidate->salaryExpectations[0]->contract);
        self::assertNull($candidate->salaryExpectations[0]->max);
        self::assertSame('EUR', $candidate->salaryExpectations[1]->currency, 'Devise par défaut.');
        self::assertSame('2026-01-05', $candidate->availability?->from);
        self::assertSame(30, $candidate->availability?->noticePeriodDays);
        self::assertSame('backend-php', $candidate->cvVersions[0]->id);
    }

    public function testNothingIsAssumedForAnUndeclaredProfile(): void
    {
        $candidate = $this->load("skills:\n  - PHP\n")->candidate;

        self::assertNotNull($candidate);
        self::assertNull($candidate->skills[0]->level, 'Un niveau non renseigné reste inconnu.');
        self::assertNull($candidate->skills[0]->yearsOfExperience, 'Des années non renseignées restent inconnues.');
        self::assertNull($candidate->fullName);
        self::assertNull($candidate->availability);
        self::assertSame([], $candidate->experiences);
        self::assertSame([], $candidate->certifications);
        self::assertSame([], $candidate->remoteModes);
        self::assertSame([], $candidate->contracts);
        self::assertSame([], $candidate->salaryExpectations);
        self::assertNull($candidate->skill('Symfony'), 'Une compétence absente du fichier n\'est jamais supposée acquise.');
    }

    public function testSkillLookupIsCaseAndSpaceInsensitive(): void
    {
        $candidate = $this->load("skills: [Symfony Framework]\nexcluded_skills: [Java]\n")->candidate;

        self::assertNotNull($candidate?->skill('  symfony   FRAMEWORK '));
        self::assertTrue($candidate->excludes('JAVA'));
        self::assertFalse($candidate->excludes('Go'));
    }

    public function testUnquotedYamlDatesAreAccepted(): void
    {
        $candidate = $this->load(<<<'YAML'
            experiences:
              - company: A
                title: B
                start: 2020-01-15
                end: 2021-06-30
            availability:
              from: 2026-02-01
            YAML)->candidate;

        self::assertSame('2020-01', $candidate?->experiences[0]->startDate);
        self::assertSame('2021-06', $candidate->experiences[0]->endDate);
        self::assertSame('2026-02-01', $candidate->availability?->from);
    }

    public function testAnOngoingExperienceHasNoEndDate(): void
    {
        $candidate = $this->load("experiences:\n  - company: A\n    title: B\n    start: \"2023-01\"\n")->candidate;

        self::assertNull($candidate?->experiences[0]->endDate);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidProfiles(): iterable
    {
        yield 'clé inconnue (faute de frappe)' => ["skils: [PHP]\n", 'skils'];
        yield 'niveau inconnu' => ["skills:\n  - name: PHP\n    level: god\n", 'level'];
        yield 'années négatives' => ["skills:\n  - name: PHP\n    years: -1\n", 'years'];
        yield 'années déraisonnables' => ["skills:\n  - name: PHP\n    years: 200\n", 'years'];
        yield 'email invalide' => ["identity:\n  email: pas-un-email\n", 'email'];
        yield 'mois invalide' => ["experiences:\n  - company: A\n    title: B\n    start: \"mars 2020\"\n", 'AAAA-MM'];
        yield 'mois hors plage' => ["experiences:\n  - company: A\n    title: B\n    start: \"2020-13\"\n", 'AAAA-MM'];
        yield 'fin avant début' => ["experiences:\n  - company: Acme\n    title: B\n    start: \"2022-05\"\n    end: \"2021-01\"\n", 'précède'];
        yield 'compétence en double' => ["skills: [PHP, ' php ']\n", 'double'];
        yield 'compétence et exclue' => ["skills: [PHP]\nexcluded_skills: [php]\n", 'exclue'];
        yield 'contrat inconnu' => ["contracts: [portage]\n", 'portage'];
        yield 'mode de travail inconnu' => ["remote_modes: [teleportation]\n", 'teleportation'];
        yield 'mobilité inconnue' => ["location:\n  mobility: galactic\n", 'mobility'];
        yield 'salaire min > max' => ["salary:\n  - period: annual\n    min: 60000\n    max: 40000\n", 'dépasse'];
        yield 'salaire sans borne' => ["salary:\n  - period: annual\n", 'borne'];
        yield 'période inconnue' => ["salary:\n  - period: hourly\n    min: 10\n", 'period'];
        yield 'devise invalide' => ["salary:\n  - period: daily\n    min: 500\n    currency: euros\n", 'devise'];
        yield 'date de disponibilité invalide' => ["availability:\n  from: demain\n", 'AAAA-MM-JJ'];
        yield 'date de disponibilité impossible' => ["availability:\n  from: \"2026-02-31\"\n", 'AAAA-MM-JJ'];
        yield 'CV en double' => ["cvs:\n  - {id: a, label: A}\n  - {id: a, label: B}\n", 'double'];
        yield 'identifiant de CV invalide' => ["cvs:\n  - {id: 'Mon CV', label: A}\n", 'Identifiant'];
        yield 'YAML invalide' => ["skills: [PHP\n", 'YAML invalide'];
        yield 'racine non table' => ["- PHP\n- Symfony\n", 'table'];
    }

    #[DataProvider('invalidProfiles')]
    public function testRejectsInvalidProfilesNamingTheFile(string $yaml, string $expectedFragment): void
    {
        $this->write('candidate.yaml', $yaml);

        try {
            $this->loader()->load();
            self::fail('Une InvalidProfileException était attendue.');
        } catch (InvalidProfileException $e) {
            self::assertStringContainsString('candidate.yaml', $e->getMessage());
            self::assertStringContainsString($expectedFragment, $e->getMessage());
        }
    }
}
