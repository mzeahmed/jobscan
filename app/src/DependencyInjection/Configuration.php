<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Arbre de configuration du namespace `jobscan:` (`config/packages/jobscan.yaml`).
 *
 * Définit notamment `jobscan.llm.*`, qui pilote le choix du moteur LLM (Ollama,
 * LM Studio ou Gemini) sans jamais exposer ce choix au reste de l'application —
 * voir `LLMClientFactory`.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('jobscan');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('llm')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('provider')
                            ->values(['ollama', 'lmstudio', 'gemini'])
                            ->defaultValue('ollama')
                            ->info('Moteur LLM actif utilisé par LLMClientFactory.')
                        ->end()
                        ->arrayNode('ollama')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('base_url')->defaultValue('http://localhost:11434/v1')->end()
                                ->scalarNode('model')->defaultValue('qwen3:8b')->end()
                            ->end()
                        ->end()
                        ->arrayNode('lmstudio')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('base_url')->defaultValue('http://localhost:1234/v1')->end()
                                ->scalarNode('model')->defaultValue('local-model')->end()
                            ->end()
                        ->end()
                        ->arrayNode('gemini')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('api_key')->defaultValue('')->end()
                                ->scalarNode('model')->defaultValue('gemini-2.0-flash')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
