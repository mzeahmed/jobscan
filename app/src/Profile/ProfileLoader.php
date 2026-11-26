<?php

declare(strict_types=1);

namespace App\Profile;

use App\Profile\Model\Skill;
use Symfony\Component\Yaml\Yaml;
use App\Profile\Model\SearchProfile;
use App\Profile\Model\CandidateProfile;
use App\Profile\Config\CandidateConfiguration;
use Symfony\Component\Config\Definition\Processor;
use App\Profile\Config\SearchProfilesConfiguration;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Charge et valide les fichiers locaux `candidate.yaml` et `search_profiles.yaml`.
 *
 * Un fichier absent n'est pas une erreur (Jobscan fonctionne alors sans profil) ; un fichier
 * invalide lève une `InvalidProfileException` nommant le fichier et le champ fautif, sans jamais
 * reproduire le contenu du profil. Les incohérences non bloquantes sont renvoyées en avertissements.
 */
final readonly class ProfileLoader
{
    public const string CANDIDATE_FILE = 'candidate.yaml';
    public const string SEARCH_PROFILES_FILE = 'search_profiles.yaml';

    /**
     * @param string $directory Dossier des fichiers locaux (config `app.profile.directory`)
     * @param array<string, int> $defaultWeights Config `app.profile.match_weights`
     */
    public function __construct(
        private string $directory,
        private array $defaultWeights = [],
        private int $defaultMinMatchScore = 50,
    ) {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function load(): ProfileSet
    {
        $candidate = $this->loadCandidate();
        $profiles = $this->loadSearchProfiles();

        return new ProfileSet($candidate, $profiles, $this->validate($candidate, $profiles));
    }

    private function loadCandidate(): ?CandidateProfile
    {
        $config = $this->read(self::CANDIDATE_FILE, new CandidateConfiguration());
        if ($config === null) {
            return null;
        }

        try {
            return new CandidateProfileFactory()->create($config);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidProfileException(sprintf('%s : %s', self::CANDIDATE_FILE, $e->getMessage()), previous: $e);
        }
    }

    /** @return list<SearchProfile> */
    private function loadSearchProfiles(): array
    {
        $config = $this->read(self::SEARCH_PROFILES_FILE, new SearchProfilesConfiguration());
        if ($config === null) {
            return [];
        }

        $factory = new SearchProfileFactory($this->defaultWeights, $this->defaultMinMatchScore);
        $profiles = [];

        foreach ((array) ($config['search_profiles'] ?? []) as $id => $profileConfig) {
            try {
                $profiles[] = $factory->create((string) $id, is_array($profileConfig) ? $profileConfig : []);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidProfileException(sprintf('%s : %s', self::SEARCH_PROFILES_FILE, $e->getMessage()), previous: $e);
            }
        }

        return $profiles;
    }

    /**
     * Cohérence entre les deux fichiers. Lève sur une contradiction ; renvoie des avertissements
     * pour ce qui est suspect sans être faux.
     *
     * @param list<SearchProfile> $profiles
     * @return list<string>
     */
    private function validate(?CandidateProfile $candidate, array $profiles): array
    {
        $warnings = [];
        $seen = [];

        foreach ($profiles as $profile) {
            if (isset($seen[$profile->id])) {
                throw new InvalidProfileException(sprintf('%s : profil « %s » déclaré en double.', self::SEARCH_PROFILES_FILE, $profile->id));
            }

            $seen[$profile->id] = true;

            $this->assertNoContradiction($profile);

            if ($profile->cvId !== null && $candidate !== null && $candidate->cvVersion($profile->cvId) === null) {
                throw new InvalidProfileException(sprintf(
                    '%s : le profil « %s » référence la version de CV « %s » absente de %s.',
                    self::SEARCH_PROFILES_FILE,
                    $profile->id,
                    $profile->cvId,
                    self::CANDIDATE_FILE,
                ));
            }

            if ($profile->enabled && $profile->keywords === [] && $profile->titles === [] && $profile->primarySkills === []) {
                $warnings[] = sprintf('Profil « %s » : ni mot-clé, ni intitulé, ni compétence prioritaire — il ne peut rien cibler.', $profile->id);
            }

            if ($candidate === null) {
                continue;
            }

            foreach ([...$profile->primarySkills, ...$profile->secondarySkills] as $skill) {
                if ($candidate->excludes($skill)) {
                    throw new InvalidProfileException(sprintf(
                        '%s : le profil « %s » cible « %s », que %s déclare comme exclue.',
                        self::SEARCH_PROFILES_FILE,
                        $profile->id,
                        $skill,
                        self::CANDIDATE_FILE,
                    ));
                }

                if ($candidate->skill($skill) === null) {
                    $warnings[] = sprintf('Profil « %s » : la compétence « %s » n\'est pas déclarée dans %s ; elle ne sera pas comptée comme maîtrisée.', $profile->id, $skill, self::CANDIDATE_FILE);
                }
            }
        }

        if ($profiles !== [] && $candidate === null) {
            $warnings[] = sprintf('%s est absent : les profils de recherche ne peuvent pas être comparés au candidat.', self::CANDIDATE_FILE);
        }

        return $warnings;
    }

    private function assertNoContradiction(SearchProfile $profile): void
    {
        $excluded = array_map(Skill::normalize(...), $profile->excludedSkills);

        foreach ([...$profile->primarySkills, ...$profile->secondarySkills] as $skill) {
            if (in_array(Skill::normalize($skill), $excluded, true)) {
                throw new InvalidProfileException(sprintf(
                    '%s : le profil « %s » cible et exclut à la fois « %s ».',
                    self::SEARCH_PROFILES_FILE,
                    $profile->id,
                    $skill,
                ));
            }
        }

        $primary = array_map(Skill::normalize(...), $profile->primarySkills);
        foreach ($profile->secondarySkills as $skill) {
            if (in_array(Skill::normalize($skill), $primary, true)) {
                throw new InvalidProfileException(sprintf(
                    '%s : le profil « %s » classe « %s » à la fois en compétence prioritaire et secondaire.',
                    self::SEARCH_PROFILES_FILE,
                    $profile->id,
                    $skill,
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>|null null si le fichier n'existe pas
     */
    private function read(string $file, ConfigurationInterface $configuration): ?array
    {
        $path = rtrim($this->directory, '/') . '/' . $file;
        if (!is_file($path)) {
            return null;
        }

        try {
            $data = Yaml::parseFile($path, Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            // Le message de Symfony cite la ligne fautive, pas son contenu.
            throw new InvalidProfileException(sprintf('%s : YAML invalide (%s).', $file, $e->getMessage()), previous: $e);
        }

        if ($data === null) {
            $data = [];
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidProfileException(sprintf('%s : le fichier doit contenir une table YAML (clé: valeur), pas une liste.', $file));
        }

        try {
            /** @var array<string, mixed> */
            return new Processor()->processConfiguration($configuration, [$data]);
        } catch (InvalidConfigurationException $e) {
            throw new InvalidProfileException(sprintf('%s : %s', $file, $e->getMessage()), previous: $e);
        }
    }
}
