<?php

declare(strict_types=1);

namespace App\Profile;

use App\DTO\ContractType;
use App\Profile\Model\SearchProfile;
use App\Profile\Model\ScoringWeights;
use App\Profile\Model\RemotePreference;

/**
 * Construit un profil de recherche immuable depuis une configuration déjà validée par
 * `SearchProfilesConfiguration`. Les poids et le seuil absents reprennent les valeurs par défaut.
 */
final readonly class SearchProfileFactory
{
    /**
     * @param array<string, int> $defaultWeights Config `app.profile.match_weights`
     * @param int $defaultMinMatchScore Config `app.profile.default_min_match_score`
     */
    public function __construct(
        private array $defaultWeights,
        private int $defaultMinMatchScore,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function create(string $id, array $config): SearchProfile
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf('Identifiant de profil de recherche invalide : « %s » (minuscules, chiffres, « - » ou « _»).', $id));
        }

        $overrides = [];
        foreach (is_array($config['weights'] ?? null) ? $config['weights'] : [] as $criterion => $weight) {
            if ($weight !== null) {
                $overrides[(string) $criterion] = (int) $weight;
            }
        }

        try {
            $weights = ScoringWeights::create($this->defaultWeights, $overrides);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(sprintf('Profil « %s » : %s', $id, $e->getMessage()), previous: $e);
        }

        return new SearchProfile(
            id: $id,
            name: CandidateProfileFactory::nullableString($config['name'] ?? null) ?? $id,
            enabled: (bool) ($config['enabled'] ?? true),
            keywords: CandidateProfileFactory::strings($config['keywords'] ?? []),
            titles: CandidateProfileFactory::strings($config['titles'] ?? []),
            primarySkills: CandidateProfileFactory::strings($config['primary_skills'] ?? []),
            secondarySkills: CandidateProfileFactory::strings($config['secondary_skills'] ?? []),
            locations: CandidateProfileFactory::strings($config['locations'] ?? []),
            contracts: array_map(ContractType::from(...), CandidateProfileFactory::strings($config['contracts'] ?? [])),
            remoteModes: array_map(RemotePreference::from(...), CandidateProfileFactory::strings($config['remote_modes'] ?? [])),
            excludedKeywords: CandidateProfileFactory::strings($config['excluded_keywords'] ?? []),
            excludedTitles: CandidateProfileFactory::strings($config['excluded_titles'] ?? []),
            excludedSkills: CandidateProfileFactory::strings($config['excluded_skills'] ?? []),
            minMatchScore: isset($config['min_match_score']) ? (int) $config['min_match_score'] : $this->defaultMinMatchScore,
            weights: $weights,
            cvId: CandidateProfileFactory::nullableString($config['cv'] ?? null),
        );
    }
}
