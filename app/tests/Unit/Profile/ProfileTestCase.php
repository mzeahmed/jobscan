<?php

declare(strict_types=1);

namespace App\Tests\Unit\Profile;

use App\Profile\ProfileSet;
use App\Profile\ProfileLoader;
use PHPUnit\Framework\TestCase;

/**
 * Outils communs : dossier de profil temporaire isolé, jamais le dossier local de l'utilisateur.
 */
abstract class ProfileTestCase extends TestCase
{
    protected const array WEIGHTS = [
        'required_skills' => 40, 'nice_skills' => 15, 'title' => 15, 'location' => 10, 'remote' => 5,
        'contract' => 5, 'salary' => 5, 'seniority' => 3, 'languages' => 1, 'availability' => 1,
    ];

    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jobscan-profile-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    protected function write(string $file, string $yaml): void
    {
        file_put_contents($this->directory . '/' . $file, $yaml);
    }

    protected function loader(int $defaultMinScore = 50): ProfileLoader
    {
        return new ProfileLoader($this->directory, self::WEIGHTS, $defaultMinScore);
    }

    protected function load(string $candidate = '', string $searchProfiles = ''): ProfileSet
    {
        if ($candidate !== '') {
            $this->write(ProfileLoader::CANDIDATE_FILE, $candidate);
        }
        if ($searchProfiles !== '') {
            $this->write(ProfileLoader::SEARCH_PROFILES_FILE, $searchProfiles);
        }

        return $this->loader()->load();
    }
}
