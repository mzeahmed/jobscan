<?php

declare(strict_types=1);

namespace App\Profile;

use App\DTO\ContractType;
use App\Profile\Model\Skill;
use App\Profile\Model\Project;
use App\Profile\Model\Language;
use App\Profile\Model\Mobility;
use App\Profile\Model\CvVersion;
use App\Profile\Model\Experience;
use App\Profile\Model\SkillLevel;
use App\Profile\Model\Availability;
use App\Profile\Model\SalaryPeriod;
use App\Profile\Model\Certification;
use App\Profile\Model\CandidateProfile;
use App\Profile\Model\RemotePreference;
use App\Profile\Model\SalaryExpectation;
use App\Profile\Model\LocationPreferences;

/**
 * Construit le profil candidat immuable depuis une configuration déjà validée par
 * `CandidateConfiguration`, puis en vérifie la cohérence (doublons, bornes, références).
 *
 * Rien n'est complété ni supposé : un champ non renseigné reste `null` ou vide.
 */
final readonly class CandidateProfileFactory
{
    /**
     * @param array<string, mixed> $config
     * @throws \InvalidArgumentException si le profil est incohérent
     */
    public function create(array $config): CandidateProfile
    {
        $identity = self::map($config['identity'] ?? []);
        $location = self::map($config['location'] ?? []);
        $availability = isset($config['availability']) ? self::map($config['availability']) : null;

        $profile = new CandidateProfile(
            fullName: self::nullableString($identity['full_name'] ?? null),
            headline: self::nullableString($identity['headline'] ?? null),
            email: self::nullableString($identity['email'] ?? null),
            phone: self::nullableString($identity['phone'] ?? null),
            targetTitles: self::strings($config['target_titles'] ?? []),
            skills: array_map($this->skill(...), self::items($config['skills'] ?? [])),
            excludedSkills: self::strings($config['excluded_skills'] ?? []),
            experiences: array_map($this->experience(...), self::items($config['experiences'] ?? [])),
            projects: array_map($this->project(...), self::items($config['projects'] ?? [])),
            achievements: self::strings($config['achievements'] ?? []),
            certifications: array_map($this->certification(...), self::items($config['certifications'] ?? [])),
            languages: array_map($this->language(...), self::items($config['languages'] ?? [])),
            location: new LocationPreferences(
                self::strings($location['cities'] ?? []),
                isset($location['mobility']) ? Mobility::from((string) $location['mobility']) : null,
                isset($location['max_distance_km']) ? (int) $location['max_distance_km'] : null,
            ),
            remoteModes: array_map(static fn (mixed $v): RemotePreference => RemotePreference::from((string) $v), self::list($config['remote_modes'] ?? [])),
            contracts: array_map(static fn (mixed $v): ContractType => ContractType::from((string) $v), self::list($config['contracts'] ?? [])),
            salaryExpectations: array_map($this->salary(...), self::items($config['salary'] ?? [])),
            availability: $availability === null ? null : new Availability(
                self::nullableString($availability['from'] ?? null),
                isset($availability['notice_days']) ? (int) $availability['notice_days'] : null,
                self::nullableString($availability['comment'] ?? null),
            ),
            cvVersions: array_map($this->cv(...), self::items($config['cvs'] ?? [])),
        );

        $this->assertConsistent($profile);

        return $profile;
    }

    private function assertConsistent(CandidateProfile $profile): void
    {
        $seen = [];
        foreach ($profile->skills as $skill) {
            if (isset($seen[$skill->key()])) {
                throw new \InvalidArgumentException(sprintf('Compétence déclarée en double : « %s ».', $skill->name));
            }

            $seen[$skill->key()] = true;

            if ($profile->excludes($skill->name)) {
                throw new \InvalidArgumentException(sprintf('« %s » est à la fois une compétence et une compétence exclue.', $skill->name));
            }
        }

        foreach ($profile->experiences as $experience) {
            if ($experience->endDate !== null && $experience->endDate < $experience->startDate) {
                throw new \InvalidArgumentException(sprintf('Expérience « %s » : la fin précède le début.', $experience->company));
            }
        }

        foreach ($profile->salaryExpectations as $salary) {
            if ($salary->min === null && $salary->max === null) {
                throw new \InvalidArgumentException('Une prétention salariale doit avoir au moins une borne (min ou max).');
            }

            if ($salary->min !== null && $salary->max !== null && $salary->min > $salary->max) {
                throw new \InvalidArgumentException('Prétention salariale : le minimum dépasse le maximum.');
            }
        }

        $ids = [];
        foreach ($profile->cvVersions as $cv) {
            if (isset($ids[$cv->id])) {
                throw new \InvalidArgumentException(sprintf('Version de CV déclarée en double : « %s ».', $cv->id));
            }

            $ids[$cv->id] = true;
        }
    }

    /** @param array<string, mixed> $skill */
    private function skill(array $skill): Skill
    {
        return new Skill(
            (string) $skill['name'],
            isset($skill['level']) ? SkillLevel::from((string) $skill['level']) : null,
            isset($skill['years']) ? (float) $skill['years'] : null,
        );
    }

    /** @param array<string, mixed> $item */
    private function experience(array $item): Experience
    {
        return new Experience(
            (string) $item['company'],
            (string) $item['title'],
            (string) $item['start'],
            self::nullableString($item['end'] ?? null),
            self::nullableString($item['summary'] ?? null),
            self::strings($item['achievements'] ?? []),
            self::strings($item['skills'] ?? []),
        );
    }

    /** @param array<string, mixed> $item */
    private function project(array $item): Project
    {
        return new Project(
            (string) $item['name'],
            self::nullableString($item['summary'] ?? null),
            self::nullableString($item['url'] ?? null),
            self::strings($item['skills'] ?? []),
            self::strings($item['achievements'] ?? []),
        );
    }

    /** @param array<string, mixed> $item */
    private function certification(array $item): Certification
    {
        return new Certification(
            (string) $item['name'],
            self::nullableString($item['issuer'] ?? null),
            isset($item['year']) ? (int) $item['year'] : null,
        );
    }

    /** @param array<string, mixed> $item */
    private function language(array $item): Language
    {
        return new Language((string) $item['name'], self::nullableString($item['level'] ?? null));
    }

    /** @param array<string, mixed> $item */
    private function salary(array $item): SalaryExpectation
    {
        return new SalaryExpectation(
            isset($item['contract']) ? ContractType::from((string) $item['contract']) : null,
            SalaryPeriod::from((string) $item['period']),
            isset($item['min']) ? (int) $item['min'] : null,
            isset($item['max']) ? (int) $item['max'] : null,
            (string) ($item['currency'] ?? 'EUR'),
        );
    }

    /** @param array<string, mixed> $item */
    private function cv(array $item): CvVersion
    {
        return new CvVersion((string) $item['id'], (string) $item['label'], self::nullableString($item['path'] ?? null));
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @return list<array<string, mixed>> */
    private static function items(mixed $value): array
    {
        /** @var list<array<string, mixed>> */
        return array_values(array_filter(self::list($value), is_array(...)));
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', self::list($value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    public static function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
