<?php

declare(strict_types=1);

namespace App\Matching;

use App\AI\Extraction\TextMatcher;
use App\Profile\Model\SearchProfile;

/**
 * Critères éliminatoires, évalués **indépendamment du score** : une offre qui en déclenche un est écartée
 * quelle que soit sa compatibilité par ailleurs.
 *
 * Éliminatoires : mot-clé, intitulé ou compétence exclus par le profil (ou compétence exclue par le
 * candidat) exigée par l'annonce ; contrat ou mode de travail **explicitement** hors des préférences.
 * Une information inconnue n'élimine jamais : une annonce sans contrat ni mode de travail précisé n'est pas écartée.
 */
final readonly class EligibilityChecker
{
    public function __construct(private SkillCanonicalizer $skills)
    {
    }

    /** @return list<string> Raisons éliminatoires, vide si l'offre n'est pas éliminée */
    public function check(MatchPreferences $preferences, SearchProfile $profile, JobFacts $facts): array
    {
        $reasons = [];
        $requirements = $facts->requirements;

        $title = new TextMatcher($facts->title);
        $text = new TextMatcher($facts->title . ' ' . $facts->description);

        foreach ($profile->excludedKeywords as $keyword) {
            if ($text->containsWord($keyword)) {
                $reasons[] = sprintf('Mot-clé exclu « %s » présent dans l\'annonce.', $keyword);
            }
        }

        foreach ($profile->excludedTitles as $excluded) {
            if ($title->containsWord($excluded)) {
                $reasons[] = sprintf('Intitulé exclu « %s ».', $excluded);
            }
        }

        $excludedSkills = [];
        foreach ($preferences->excludedSkills as $skill) {
            $excludedSkills[$this->skills->key($skill)] = true;
        }
        foreach ($requirements->requiredSkills as $skill) {
            if (isset($excludedSkills[$this->skills->key($skill)])) {
                $reasons[] = sprintf('L\'annonce exige « %s », une compétence exclue.', $skill);
            }
        }

        if ($requirements->contractTypes !== [] && $preferences->contracts !== []
            && !array_any($requirements->contractTypes, static fn ($type): bool => in_array($type, $preferences->contracts, true))) {
            $reasons[] = sprintf(
                'Contrat proposé (%s) hors des contrats recherchés (%s).',
                implode(', ', array_map(static fn ($type): string => $type->value, $requirements->contractTypes)),
                implode(', ', array_map(static fn ($type): string => $type->value, $preferences->contracts)),
            );
        }

        if ($requirements->remoteMode !== null && $preferences->remoteModes !== []
            && !in_array($requirements->remoteMode, $preferences->remoteModes, true)) {
            $reasons[] = sprintf(
                'Mode de travail (%s) hors des modes acceptés (%s).',
                $requirements->remoteMode->value,
                implode(', ', array_map(static fn ($mode): string => $mode->value, $preferences->remoteModes)),
            );
        }

        return $reasons;
    }
}
