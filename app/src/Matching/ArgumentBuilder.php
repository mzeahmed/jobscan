<?php

declare(strict_types=1);

namespace App\Matching;

use App\AI\Extraction\LanguageGroups;
use App\Profile\Model\CandidateProfile;

/**
 * Faits du profil à mettre en avant pour une offre donnée.
 *
 * Chaque argument est tiré **littéralement** de ce que le candidat a déclaré (compétences et niveaux,
 * expériences, projets, langues) : aucun résultat, aucun chiffre ni aucune compétence n'est ajouté.
 */
final readonly class ArgumentBuilder
{
    /** Débuts des arguments : l'assistant de candidature s'en sert pour les reformuler, sans rien ajouter. */
    public const string PREFIX_REQUIRED = 'Compétences exigées déclarées : ';
    public const string PREFIX_NICE = 'Compétences appréciées ou citées déclarées : ';
    public const string PREFIX_EXPERIENCE = 'Expérience pertinente : ';
    public const string PREFIX_PROJECT = 'Projet pertinent : ';
    public const string PREFIX_LANGUAGE = 'Langue exigée couverte : ';

    private const int MAX_EXPERIENCES = 3;
    private const int MAX_PROJECTS = 2;

    public function __construct(
        private SkillCanonicalizer $skills
    ) {
    }

    /**
     * @param list<CriterionResult> $criteria
     * @return list<string>
     */
    public function build(CandidateProfile $candidate, JobFacts $facts, SkillComparison $comparison, array $criteria): array
    {
        $arguments = [];

        if ($comparison->matchedRequired !== []) {
            $arguments[] = self::PREFIX_REQUIRED . implode(', ', array_map($this->describe(...), $comparison->matchedRequired)) . '.';
        }
        if ($comparison->matchedNice !== []) {
            $arguments[] = self::PREFIX_NICE . implode(', ', array_map($this->describe(...), $comparison->matchedNice)) . '.';
        }

        $jobSkills = [];
        foreach ($facts->requirements->allSkills() as $skill) {
            $jobSkills[$this->skills->key($skill)] = true;
        }

        foreach ($this->relevant($candidate->experiences, $jobSkills, self::MAX_EXPERIENCES) as [$experience, $shared]) {
            $arguments[] = sprintf(self::PREFIX_EXPERIENCE . '%s chez %s (%s).', $experience->title, $experience->company, implode(', ', $shared));
        }
        foreach ($this->relevant($candidate->projects, $jobSkills, self::MAX_PROJECTS) as [$project, $shared]) {
            $arguments[] = sprintf(self::PREFIX_PROJECT . '%s (%s).', $project->name, implode(', ', $shared));
        }

        $languages = $this->coveredLanguages($candidate, $facts);
        if ($languages !== []) {
            $arguments[] = self::PREFIX_LANGUAGE . implode(', ', $languages) . '.';
        }

        foreach ($criteria as $criterion) {
            if ($criterion->status === CriterionStatus::Match && in_array($criterion->criterion, ['salary', 'remote', 'contract'], true)) {
                $arguments[] = $criterion->detail;
            }
        }

        return $arguments;
    }

    private function describe(SkillMatch $match): string
    {
        $details = array_filter([
            $match->declared->level?->value,
            $match->declared->yearsOfExperience === null ? null : sprintf('%s an(s)', $match->declared->yearsOfExperience),
        ]);

        return $details === [] ? $match->jobSkill : sprintf('%s (%s)', $match->jobSkill, implode(', ', $details));
    }

    /**
     * Expériences ou projets dont les compétences recoupent celles de l'annonce, les plus pertinents d'abord.
     *
     * @template T of object
     * @param list<T> $items
     * @param array<string, true> $jobSkills
     * @return list<array{0: T, 1: list<string>}>
     */
    private function relevant(array $items, array $jobSkills, int $limit): array
    {
        $scored = [];
        foreach ($items as $item) {
            /** @var list<string> $itemSkills */
            $itemSkills = $item->skills;
            $shared = array_values(array_filter($itemSkills, fn (string $skill): bool => isset($jobSkills[$this->skills->key($skill)])));
            if ($shared !== []) {
                $scored[] = [$item, $shared];
            }
        }

        usort($scored, static fn (array $a, array $b): int => count($b[1]) <=> count($a[1]));

        return array_slice($scored, 0, $limit);
    }

    /** @return list<string> */
    private function coveredLanguages(CandidateProfile $candidate, JobFacts $facts): array
    {
        $covered = [];
        foreach ($facts->requirements->languages as $required) {
            if (!$required->required) {
                continue;
            }

            foreach ($candidate->languages as $language) {
                if (LanguageGroups::key($language->name) === LanguageGroups::key($required->name)) {
                    $covered[] = $language->level === null ? $language->name : sprintf('%s (%s)', $language->name, $language->level);
                }
            }
        }

        return $covered;
    }
}
