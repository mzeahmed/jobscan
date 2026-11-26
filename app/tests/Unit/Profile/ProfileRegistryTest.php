<?php

declare(strict_types=1);

namespace App\Tests\Unit\Profile;

use App\Profile\ProfileRegistry;
use App\Profile\Model\ScoringWeights;
use App\Profile\InvalidProfileException;

final class ProfileRegistryTest extends ProfileTestCase
{
    public function testLoadingIsLazyAndAnInvalidFileDoesNotBreakConstruction(): void
    {
        $this->write('candidate.yaml', "skils: [PHP]\n");

        $registry = new ProfileRegistry($this->loader());

        $this->expectException(InvalidProfileException::class);
        $registry->candidate();
    }

    public function testTheProfileIsLoadedOnceThenReloadedOnDemand(): void
    {
        $this->write('candidate.yaml', "skills: [PHP]\n");
        $registry = new ProfileRegistry($this->loader());

        self::assertCount(1, $registry->candidate()?->skills ?? []);

        $this->write('candidate.yaml', "skills: [PHP, Go]\n");
        self::assertCount(1, $registry->candidate()?->skills ?? [], 'Mémorisé : le fichier n\'est pas relu.');

        $registry->reload();
        self::assertCount(2, $registry->candidate()?->skills ?? []);
    }

    public function testExposesEnabledProfilesAndLookup(): void
    {
        $this->write('search_profiles.yaml', "search_profiles:\n  a:\n    keywords: [php]\n  b:\n    enabled: false\n    keywords: [go]\n");
        $registry = new ProfileRegistry($this->loader());

        self::assertCount(1, $registry->enabledSearchProfiles());
        self::assertSame('b', $registry->searchProfile('b')?->id);
        self::assertNull($registry->candidate());
    }

    public function testWeightsFallBackToDefaultsAndValidateTheirTotal(): void
    {
        $defaults = ['required_skills' => 40, 'title' => 10];

        $weights = ScoringWeights::create($defaults, ['title' => 20]);

        self::assertSame(40, $weights->weight('required_skills'));
        self::assertSame(20, $weights->weight('title'));
        self::assertSame(0, $weights->weight('remote'), 'Critère sans valeur : neutralisé.');
        self::assertSame(0, $weights->weight('inexistant'));
        self::assertSame(60, $weights->total());

        $this->expectException(\InvalidArgumentException::class);
        ScoringWeights::create([], []);
    }

    public function testTheShippedExamplesAreValidAndConsistent(): void
    {
        $examples = __DIR__ . '/../../../config/profile';
        copy($examples . '/candidate.example.yaml', $this->directory . '/candidate.yaml');
        copy($examples . '/search_profiles.example.yaml', $this->directory . '/search_profiles.yaml');

        $set = $this->loader()->load();

        self::assertNotNull($set->candidate);
        self::assertSame([], $set->warnings, 'Les exemples doivent être valides et cohérents entre eux.');
        self::assertCount(2, $set->searchProfiles);
        self::assertNotNull($set->candidate->cvVersion('backend-php'));
        self::assertTrue($set->candidate->excludes('COBOL'));
    }

    public function testExamplesAreClearlyMarkedAsFictional(): void
    {
        foreach (['candidate', 'search_profiles'] as $name) {
            $content = (string) file_get_contents(__DIR__ . '/../../../config/profile/' . $name . '.example.yaml');

            self::assertStringContainsString('EXEMPLE', $content);
            self::assertStringContainsString('fictives', $content);
        }

        self::assertStringContainsString('example.com', (string) file_get_contents(__DIR__ . '/../../../config/profile/candidate.example.yaml'));
    }

    public function testThePersonalProfileFolderIsExcludedFromGitAndDocker(): void
    {
        $gitignore = (string) file_get_contents(__DIR__ . '/../../../config/profile/local/.gitignore');
        self::assertStringContainsString("*\n", $gitignore);
        self::assertStringContainsString('!.gitignore', $gitignore);

        $dockerignore = __DIR__ . '/../../../../.dockerignore';
        if (is_file($dockerignore)) {
            self::assertStringContainsString('app/config/profile/local', (string) file_get_contents($dockerignore));
        }
    }
}
