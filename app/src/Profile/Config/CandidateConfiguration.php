<?php

declare(strict_types=1);

namespace App\Profile\Config;

use App\DTO\ContractType;
use App\Profile\Model\Mobility;
use App\Profile\Model\SkillLevel;
use App\Profile\Model\SalaryPeriod;
use App\Profile\Model\RemotePreference;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Schéma du fichier local `candidate.yaml`. Les clés inconnues sont rejetées : une faute de frappe
 * ne doit pas faire croire qu'une information est prise en compte.
 */
final class CandidateConfiguration implements ConfigurationInterface
{
    private const string SLUG = '/^[a-z0-9][a-z0-9_-]{0,49}$/';

    /** @return list<string> */
    public static function contractValues(): array
    {
        $values = [];
        foreach (ContractType::cases() as $type) {
            if ($type !== ContractType::Unknown) {
                $values[] = $type->value;
            }
        }

        return $values;
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('candidate');

        $tree->getRootNode()->children()
            ->arrayNode('identity')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('full_name')->defaultNull()->end()
                    ->scalarNode('headline')->defaultNull()->end()
                    ->scalarNode('email')
                        ->defaultNull()
                        ->validate()
                            ->ifTrue(static fn (mixed $v): bool => $v !== null && filter_var($v, FILTER_VALIDATE_EMAIL) === false)
                            ->thenInvalid('Adresse email invalide.')
                        ->end()
                    ->end()
                    ->scalarNode('phone')->defaultNull()->end()
                ->end()
            ->end()
            ->arrayNode('target_titles')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->arrayNode('skills')
                ->arrayPrototype()
                    ->beforeNormalization()->ifString()->then(Normalizers::nameFromString())->end()
                    ->children()
                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                        ->enumNode('level')->values(array_column(SkillLevel::cases(), 'value'))->defaultNull()->end()
                        ->floatNode('years')->min(0)->max(60)->defaultNull()->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('excluded_skills')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->arrayNode('experiences')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('company')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('title')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('start')->isRequired()
                            ->beforeNormalization()->always(Normalizers::date('Y-m'))->end()
                            ->validate()->ifTrue(self::invalidMonth(...))->thenInvalid('Mois attendu au format AAAA-MM.')->end()
                        ->end()
                        ->scalarNode('end')->defaultNull()
                            ->beforeNormalization()->always(Normalizers::date('Y-m'))->end()
                            ->validate()->ifTrue(static fn (mixed $v): bool => $v !== null && self::invalidMonth($v))->thenInvalid('Mois attendu au format AAAA-MM (omis si l\'expérience est en cours).')->end()
                        ->end()
                        ->scalarNode('summary')->defaultNull()->end()
                        ->arrayNode('achievements')->scalarPrototype()->cannotBeEmpty()->end()->end()
                        ->arrayNode('skills')->scalarPrototype()->cannotBeEmpty()->end()->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('projects')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('summary')->defaultNull()->end()
                        ->scalarNode('url')->defaultNull()->end()
                        ->arrayNode('skills')->scalarPrototype()->cannotBeEmpty()->end()->end()
                        ->arrayNode('achievements')->scalarPrototype()->cannotBeEmpty()->end()->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('achievements')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->arrayNode('certifications')
                ->arrayPrototype()
                    ->beforeNormalization()->ifString()->then(Normalizers::nameFromString())->end()
                    ->children()
                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('issuer')->defaultNull()->end()
                        ->integerNode('year')->min(1950)->max(2100)->defaultNull()->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('languages')
                ->arrayPrototype()
                    ->beforeNormalization()->ifString()->then(Normalizers::nameFromString())->end()
                    ->children()
                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('level')->defaultNull()->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('location')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('cities')->beforeNormalization()->ifString()->then(Normalizers::toList())->end()->scalarPrototype()->cannotBeEmpty()->end()->end()
                    ->enumNode('mobility')->values(array_column(Mobility::cases(), 'value'))->defaultNull()->end()
                    ->integerNode('max_distance_km')->min(0)->max(20000)->defaultNull()->end()
                ->end()
            ->end()
            ->arrayNode('remote_modes')->enumPrototype()->values(array_column(RemotePreference::cases(), 'value'))->end()->end()
            ->arrayNode('contracts')->enumPrototype()->values(self::contractValues())->end()->end()
            ->arrayNode('salary')
                ->arrayPrototype()
                    ->children()
                        ->enumNode('contract')->values(self::contractValues())->defaultNull()->end()
                        ->enumNode('period')->values(array_column(SalaryPeriod::cases(), 'value'))->isRequired()->end()
                        ->integerNode('min')->min(0)->defaultNull()->end()
                        ->integerNode('max')->min(0)->defaultNull()->end()
                        ->scalarNode('currency')->defaultValue('EUR')
                            ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[A-Z]{3}$/', $v) !== 1)->thenInvalid('Code devise ISO attendu (ex. EUR).')->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('availability')
                ->children()
                    ->scalarNode('from')->defaultNull()
                        ->beforeNormalization()->always(Normalizers::date('Y-m-d'))->end()
                        ->validate()->ifTrue(static fn (mixed $v): bool => $v !== null && !self::validDay($v))->thenInvalid('Date attendue au format AAAA-MM-JJ.')->end()
                    ->end()
                    ->integerNode('notice_days')->min(0)->max(365)->defaultNull()->end()
                    ->scalarNode('comment')->defaultNull()->end()
                ->end()
            ->end()
            ->arrayNode('cvs')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('id')->isRequired()
                            ->validate()->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match(self::SLUG, $v) !== 1)->thenInvalid('Identifiant attendu : minuscules, chiffres, « - » ou « _ ».')->end()
                        ->end()
                        ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('path')->defaultNull()->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $tree;
    }

    public static function invalidMonth(mixed $value): bool
    {
        return !is_string($value) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1;
    }

    public static function validDay(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
