<?php

declare(strict_types=1);

namespace App\Profile\Model;

/**
 * Profil professionnel du candidat, tel que déclaré dans le fichier local `candidate.yaml`.
 *
 * Ce profil est la **seule** source des informations utilisées pour le matching et, plus tard,
 * pour la préparation de candidatures : rien n'y est complété ni supposé. Il contient des
 * données personnelles et ne doit jamais être versionné ni transmis à un LLM distant sans
 * autorisation explicite.
 */
final readonly class CandidateProfile
{
    /**
     * @param list<string> $targetTitles Intitulés de poste recherchés
     * @param list<Skill> $skills
     * @param list<string> $excludedSkills Compétences exclues ou non maîtrisées
     * @param list<Experience> $experiences
     * @param list<Project> $projects
     * @param list<string> $achievements Réalisations transverses, telles que saisies
     * @param list<Certification> $certifications
     * @param list<Language> $languages
     * @param list<RemotePreference> $remoteModes Modes de travail acceptés (vide : aucune préférence)
     * @param list<\App\DTO\ContractType> $contracts Types de contrat recherchés (vide : aucune préférence)
     * @param list<SalaryExpectation> $salaryExpectations
     * @param list<CvVersion> $cvVersions
     */
    public function __construct(
        public ?string $fullName,
        public ?string $headline,
        public ?string $email,
        public ?string $phone,
        public array $targetTitles,
        public array $skills,
        public array $excludedSkills,
        public array $experiences,
        public array $projects,
        public array $achievements,
        public array $certifications,
        public array $languages,
        public LocationPreferences $location,
        public array $remoteModes,
        public array $contracts,
        public array $salaryExpectations,
        public ?Availability $availability,
        public array $cvVersions,
    ) {
    }

    /**
     * Années d'expérience cumulées d'après les expériences déclarées (périodes qui se chevauchent
     * comptées une seule fois). `null` si aucune expérience n'est déclarée : l'expérience reste inconnue,
     * elle n'est pas supposée nulle.
     */
    public function experienceYears(\DateTimeImmutable $now): ?float
    {
        if ($this->experiences === []) {
            return null;
        }

        $intervals = [];
        foreach ($this->experiences as $experience) {
            $start = self::monthIndex($experience->startDate);
            $end = $experience->endDate === null ? self::monthIndex($now->format('Y-m')) : self::monthIndex($experience->endDate);
            if ($end >= $start) {
                $intervals[] = [$start, $end + 1];
            }
        }

        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $months = 0;
        $coveredUntil = null;
        foreach ($intervals as [$start, $end]) {
            $from = $coveredUntil === null ? $start : max($start, $coveredUntil);
            if ($end > $from) {
                $months += $end - $from;
            }
            $coveredUntil = max($coveredUntil ?? $end, $end);
        }

        return round($months / 12, 1);
    }

    private static function monthIndex(string $month): int
    {
        return ((int) substr($month, 0, 4)) * 12 + ((int) substr($month, 5, 2)) - 1;
    }

    public function skill(string $name): ?Skill
    {
        $key = Skill::normalize($name);

        return array_find($this->skills, static fn (Skill $skill): bool => $skill->key() === $key);
    }

    public function excludes(string $skill): bool
    {
        $key = Skill::normalize($skill);

        return array_any($this->excludedSkills, static fn (string $excluded): bool => Skill::normalize($excluded) === $key);
    }

    public function cvVersion(string $id): ?CvVersion
    {
        return array_find($this->cvVersions, static fn (CvVersion $cv): bool => $cv->id === $id);
    }
}
