<?php

declare(strict_types=1);

namespace App\Matching;

use App\DTO\Seniority;
use App\AI\Extraction\TextMatcher;
use App\Profile\Model\SearchProfile;
use App\AI\Extraction\LanguageGroups;
use App\Profile\Model\ScoringWeights;
use App\AI\Extraction\JobRequirements;
use App\AI\Extraction\ExtractionSource;
use App\Profile\Model\CandidateProfile;
use App\Profile\Model\RemotePreference;

/**
 * Évalue chacun des critères du score de compatibilité, avec son explication.
 *
 * Principe : une information qui manque (dans l'annonce ou dans le profil) rend le critère **inconnu** ou
 * **sans objet**, jamais « non satisfait » : il sort du score au lieu de le faire baisser. Seul un écart
 * constaté entre l'annonce et le profil compte comme un échec.
 */
final readonly class CriteriaEvaluator
{
    /** Lieux qui, dans un profil, désignent le télétravail plutôt qu'une ville. */
    private const array REMOTE_PLACES = ['remote', 'teletravail', 'full remote', 'a distance'];

    /**
     * @param array<string, int|float> $seniorityYears Années d'expérience attendues par niveau de séniorité
     */
    public function __construct(private array $seniorityYears)
    {
    }

    /** @return list<CriterionResult> Un résultat par critère de `ScoringWeights::CRITERIA`, dans cet ordre */
    public function evaluate(
        CandidateProfile $candidate,
        SearchProfile $profile,
        MatchPreferences $preferences,
        JobFacts $facts,
        SkillComparison $skills,
        \DateTimeImmutable $now,
    ): array {
        $results = [];
        foreach (ScoringWeights::CRITERIA as $criterion) {
            [$status, $score, $detail] = match ($criterion) {
                'required_skills' => $this->requiredSkills($facts->requirements, $skills),
                'nice_skills' => $this->niceSkills($skills),
                'title' => $this->title($preferences, $facts),
                'location' => $this->location($preferences, $facts),
                'remote' => $this->remote($preferences, $facts->requirements),
                'contract' => $this->contract($preferences, $facts->requirements),
                'salary' => $this->salary($candidate, $facts->requirements),
                'seniority' => $this->seniority($candidate, $facts->requirements, $now),
                'languages' => $this->languages($candidate, $facts->requirements),
                default => [CriterionStatus::NotApplicable, null, 'Date de démarrage non extraite des annonces.'],
            };

            $results[] = new CriterionResult($criterion, $status, $score, $profile->weights->weight($criterion), $detail);
        }

        return $results;
    }

    /** @return array{CriterionStatus, ?float, string} */
    private function requiredSkills(JobRequirements $requirements, SkillComparison $skills): array
    {
        if ($requirements->requiredSkills === []) {
            return $requirements->source === ExtractionSource::Heuristic
                ? [CriterionStatus::Unknown, null, 'Compétences exigées indéterminées (extraction heuristique).']
                : [CriterionStatus::NotApplicable, null, 'L\'annonce n\'exige aucune compétence précise.'];
        }

        return $this->coverage(count($skills->matchedRequired), count($skills->missingRequired), 'compétences exigées couvertes', $skills->missingRequired);
    }

    /** @return array{CriterionStatus, ?float, string} */
    private function niceSkills(SkillComparison $skills): array
    {
        if ($skills->matchedNice === [] && $skills->missingNice === []) {
            return [CriterionStatus::NotApplicable, null, 'Aucune compétence appréciée ou citée dans l\'annonce.'];
        }

        return $this->coverage(count($skills->matchedNice), count($skills->missingNice), 'compétences appréciées ou citées couvertes', $skills->missingNice);
    }

    /**
     * @param list<string> $missing
     * @return array{CriterionStatus, ?float, string}
     */
    private function coverage(int $matched, int $missingCount, string $label, array $missing): array
    {
        $total = $matched + $missingCount;
        $score = $matched / $total;
        $detail = sprintf('%d/%d %s', $matched, $total, $label) . ($missing === [] ? '.' : ' — manque : ' . implode(', ', $missing) . '.');

        return [match (true) {
            $matched === $total => CriterionStatus::Match,
            $matched === 0 => CriterionStatus::Mismatch,
            default => CriterionStatus::Partial,
        }, $score, $detail];
    }

    /**
     * Pertinence du poste pour la stratégie du profil : intitulé, mots-clés, compétences ciblées.
     *
     * @return array{CriterionStatus, ?float, string}
     */
    private function title(MatchPreferences $preferences, JobFacts $facts): array
    {
        if ($preferences->titles === [] && $preferences->keywords === [] && $preferences->primarySkills === []) {
            return [CriterionStatus::NotApplicable, null, 'Aucun intitulé, mot-clé ni compétence ciblée dans le profil.'];
        }

        $title = new TextMatcher($facts->title);
        $description = new TextMatcher($facts->description);
        $best = 0.0;
        $why = '';
        $consider = static function (float $score, string $reason) use (&$best, &$why): void {
            if ($score > $best) {
                $best = $score;
                $why = $reason;
            }
        };

        foreach ($preferences->titles as $wanted) {
            if ($title->containsWord($wanted)) {
                $consider(1.0, sprintf('l\'intitulé recherché « %s » figure dans le titre', $wanted));

                continue;
            }

            $tokens = array_values(array_filter(
                explode(' ', trim((string) preg_replace('/[^a-z0-9]+/', ' ', TextMatcher::fold($wanted)))),
                static fn (string $token): bool => strlen($token) > 2,
            ));
            if ($tokens !== []) {
                $common = count(array_filter($tokens, $title->containsWord(...)));
                if ($common / count($tokens) >= 0.6) {
                    $consider(0.8, sprintf('le titre partage l\'essentiel de « %s »', $wanted));
                }
            }
        }

        foreach ($preferences->keywords as $keyword) {
            if ($title->containsWord($keyword)) {
                $consider(0.7, sprintf('le mot-clé « %s » figure dans le titre', $keyword));
            } elseif ($description->containsWord($keyword)) {
                $consider(0.4, sprintf('le mot-clé « %s » figure dans la description', $keyword));
            }
        }

        $primary = array_flip(array_map(TextMatcher::fold(...), $preferences->primarySkills));
        foreach ($facts->requirements->allSkills() as $skill) {
            if (isset($primary[TextMatcher::fold($skill)])) {
                $consider(0.5, sprintf('la compétence ciblée « %s » est citée', $skill));
            }
        }

        if ($best <= 0.0) {
            return [CriterionStatus::Mismatch, 0.0, 'Ni l\'intitulé, ni les mots-clés, ni les compétences ciblées du profil ne correspondent.'];
        }

        return [$best >= 0.8 ? CriterionStatus::Match : CriterionStatus::Partial, $best, ucfirst($why) . '.'];
    }

    /** @return array{CriterionStatus, ?float, string} */
    private function location(MatchPreferences $preferences, JobFacts $facts): array
    {
        if ($preferences->locations === []) {
            return [CriterionStatus::NotApplicable, null, 'Aucun lieu souhaité dans le profil.'];
        }

        if ($facts->requirements->remoteMode === RemotePreference::Remote) {
            return [CriterionStatus::Match, 1.0, 'Poste en télétravail complet : le lieu importe peu.'];
        }

        $places = [];
        $wantsRemote = false;
        foreach ($preferences->locations as $location) {
            if (in_array(TextMatcher::fold(trim($location)), self::REMOTE_PLACES, true)) {
                $wantsRemote = true;
            } else {
                $places[] = $location;
            }
        }

        $jobText = $facts->locationText();
        if ($jobText === '') {
            return [CriterionStatus::Unknown, null, 'Lieu non précisé par l\'annonce.'];
        }

        $matcher = new TextMatcher($jobText);
        $found = array_values(array_filter($places, $matcher->containsWord(...)));
        if ($found !== []) {
            return [CriterionStatus::Match, 1.0, sprintf('Lieu correspondant : %s.', implode(', ', $found))];
        }

        if ($wantsRemote && $matcher->containsAny(self::REMOTE_PLACES)) {
            return [CriterionStatus::Match, 1.0, 'L\'annonce mentionne le télétravail, que vous recherchez.'];
        }

        return [CriterionStatus::Mismatch, 0.0, sprintf('Lieu de l\'annonce (%s) hors des lieux souhaités (%s).', $jobText, implode(', ', $preferences->locations))];
    }

    /** @return array{CriterionStatus, ?float, string} */
    private function remote(MatchPreferences $preferences, JobRequirements $requirements): array
    {
        if ($preferences->remoteModes === []) {
            return [CriterionStatus::NotApplicable, null, 'Aucune préférence de mode de travail.'];
        }
        if ($requirements->remoteMode === null) {
            return [CriterionStatus::Unknown, null, 'Mode de travail non précisé par l\'annonce.'];
        }

        $position = array_search($requirements->remoteMode, $preferences->remoteModes, true);
        if ($position === false) {
            return [CriterionStatus::Mismatch, 0.0, sprintf('Mode de travail « %s » hors des modes acceptés.', $requirements->remoteMode->value)];
        }

        // Le premier mode de la liste est le préféré.
        return $position === 0
            ? [CriterionStatus::Match, 1.0, sprintf('Mode de travail préféré : %s.', $requirements->remoteMode->value)]
            : [CriterionStatus::Partial, 0.8, sprintf('Mode de travail accepté, moins préféré : %s.', $requirements->remoteMode->value)];
    }

    /** @return array{CriterionStatus, ?float, string} */
    private function contract(MatchPreferences $preferences, JobRequirements $requirements): array
    {
        if ($preferences->contracts === []) {
            return [CriterionStatus::NotApplicable, null, 'Aucune préférence de contrat.'];
        }
        if ($requirements->contractTypes === []) {
            return [CriterionStatus::Unknown, null, 'Type de contrat non précisé par l\'annonce.'];
        }

        $common = array_values(array_filter($requirements->contractTypes, static fn ($type): bool => in_array($type, $preferences->contracts, true)));

        return $common === []
            ? [CriterionStatus::Mismatch, 0.0, 'Contrat proposé hors des contrats recherchés.']
            : [CriterionStatus::Match, 1.0, sprintf('Contrat recherché proposé : %s.', implode(', ', array_map(static fn ($type): string => $type->value, $common)))];
    }

    /**
     * Compare la rémunération annoncée à la prétention de même période (et de même contrat si l'annonce le précise).
     * Aucune conversion de devise ni de période.
     *
     * @return array{CriterionStatus, ?float, string}
     */
    private function salary(CandidateProfile $candidate, JobRequirements $requirements): array
    {
        if ($candidate->salaryExpectations === []) {
            return [CriterionStatus::NotApplicable, null, 'Aucune prétention salariale déclarée.'];
        }

        $salary = $requirements->salary;
        if ($salary === null) {
            return [CriterionStatus::Unknown, null, 'Rémunération non annoncée.'];
        }

        $expectation = array_find(
            $candidate->salaryExpectations,
            static fn ($expected): bool => $expected->period === $salary->period
                && ($expected->contract === null || $requirements->contractTypes === [] || in_array($expected->contract, $requirements->contractTypes, true)),
        );
        if ($expectation === null || $expectation->min === null) {
            return [CriterionStatus::NotApplicable, null, sprintf('Aucune prétention déclarée comparable (rémunération %s).', $salary->period->value)];
        }
        if ($expectation->currency !== $salary->currency) {
            return [CriterionStatus::Unknown, null, sprintf('Devise de l\'annonce (%s) différente de celle de la prétention (%s) : non convertie.', $salary->currency, $expectation->currency)];
        }

        $low = $salary->min ?? $salary->max ?? 0;
        $high = $salary->max ?? $salary->min ?? 0;
        $range = $salary->min !== null && $salary->max !== null && $salary->min !== $salary->max ? sprintf('%d–%d', $salary->min, $salary->max) : (string) $high;

        if ($low >= $expectation->min) {
            return [CriterionStatus::Match, 1.0, sprintf('Rémunération annoncée (%s %s) au moins égale à la prétention (%d).', $range, $salary->currency, $expectation->min)];
        }
        if ($high >= $expectation->min) {
            return [CriterionStatus::Partial, 0.7, sprintf('La fourchette annoncée (%s %s) recoupe la prétention (%d).', $range, $salary->currency, $expectation->min)];
        }

        // 10 % sous la prétention retire 0,3 point ; 33 % ou plus ramène le critère à zéro.
        $score = max(0.0, 1 - 3 * (($expectation->min - $high) / $expectation->min));

        return [$score >= 0.5 ? CriterionStatus::Partial : CriterionStatus::Mismatch, $score, sprintf('Rémunération annoncée (%s %s) inférieure à la prétention (%d).', $range, $salary->currency, $expectation->min)];
    }

    /**
     * Expérience cumulée du candidat face aux années demandées, ou au palier du niveau annoncé. Une
     * expérience supérieure à celle demandée n'est pas pénalisée.
     *
     * @return array{CriterionStatus, ?float, string}
     */
    private function seniority(CandidateProfile $candidate, JobRequirements $requirements, \DateTimeImmutable $now): array
    {
        $fromLevel = $requirements->minYearsExperience === null && $requirements->seniority !== Seniority::Unknown;
        $required = $requirements->minYearsExperience
            ?? ($requirements->seniority !== Seniority::Unknown ? ($this->seniorityYears[$requirements->seniority->value] ?? null) : null);

        if ($required === null) {
            return [CriterionStatus::Unknown, null, 'Séniorité et années d\'expérience non précisées par l\'annonce.'];
        }

        $years = $candidate->experienceYears($now);
        if ($years === null) {
            return [CriterionStatus::Unknown, null, 'Expérience du candidat non déclarée.'];
        }

        $source = $fromLevel ? sprintf('niveau « %s »', $requirements->seniority->value) : 'annonce';
        if ($required <= 0 || $years >= $required) {
            return [CriterionStatus::Match, 1.0, sprintf('Expérience cumulée de %s an(s) pour %s an(s) demandés (%s).', $years, $required, $source)];
        }

        $score = $years / $required;

        return [$score >= 0.5 ? CriterionStatus::Partial : CriterionStatus::Mismatch, $score, sprintf('Expérience cumulée de %s an(s) pour %s an(s) demandés (%s).', $years, $required, $source)];
    }

    /** @return array{CriterionStatus, ?float, string} */
    private function languages(CandidateProfile $candidate, JobRequirements $requirements): array
    {
        $required = array_values(array_filter($requirements->languages, static fn ($language): bool => $language->required));
        if ($required === []) {
            return [CriterionStatus::NotApplicable, null, 'Aucune langue exigée par l\'annonce.'];
        }
        if ($candidate->languages === []) {
            return [CriterionStatus::Unknown, null, 'Langues du candidat non déclarées.'];
        }

        $spoken = array_map(static fn ($language): string => LanguageGroups::key($language->name), $candidate->languages);
        $missing = array_values(array_map(
            static fn ($language): string => $language->name,
            array_filter($required, static fn ($language): bool => !in_array(LanguageGroups::key($language->name), $spoken, true)),
        ));

        return $this->coverage(count($required) - count($missing), count($missing), 'langues exigées parlées', $missing);
    }
}
