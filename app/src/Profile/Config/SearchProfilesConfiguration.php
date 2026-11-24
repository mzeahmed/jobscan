<?php

declare(strict_types=1);

namespace App\Profile\Config;

use App\Profile\Model\ScoringWeights;
use App\Profile\Model\RemotePreference;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Schéma du fichier local `search_profiles.yaml` : plusieurs stratégies de recherche indépendantes,
 * indexées par un identifiant court (`backend-php`, `devops`…).
 */
final class SearchProfilesConfiguration implements ConfigurationInterface
{
    private const array LISTS = [
        'keywords', 'titles', 'primary_skills', 'secondary_skills', 'locations',
        'excluded_keywords', 'excluded_titles', 'excluded_skills',
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('search_profiles_file');

        $profile = $tree->getRootNode()->children()
            ->arrayNode('search_profiles')
                ->normalizeKeys(false)
                ->useAttributeAsKey('id')
                ->arrayPrototype()
                    ->children();

        $profile->scalarNode('name')->defaultNull();
        $profile->booleanNode('enabled')->defaultTrue();

        foreach (self::LISTS as $list) {
            $this->stringList($profile, $list);
        }

        $profile->arrayNode('contracts')
            ->beforeNormalization()->ifString()->then(Normalizers::toList())->end()
            ->enumPrototype()->values(CandidateConfiguration::contractValues());
        $profile->arrayNode('remote_modes')
            ->beforeNormalization()->ifString()->then(Normalizers::toList())->end()
            ->enumPrototype()->values(array_column(RemotePreference::cases(), 'value'));
        $profile->integerNode('min_match_score')->min(0)->max(100)->defaultNull();

        $weights = $profile->arrayNode('weights')->children();
        foreach (ScoringWeights::CRITERIA as $criterion) {
            $weights->integerNode($criterion)->min(0)->max(1000)->defaultNull();
        }

        $profile->scalarNode('cv')->defaultNull();

        return $tree;
    }

    private function stringList(NodeBuilder $node, string $name): void
    {
        $node->arrayNode($name)
            ->beforeNormalization()->ifString()->then(Normalizers::toList())->end()
            ->scalarPrototype()->cannotBeEmpty();
    }
}
