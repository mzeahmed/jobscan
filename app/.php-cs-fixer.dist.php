<?php

declare(strict_types=1);

/**
 * Config php-cs-fixer — remplace Laravel Pint (packagé en phar figé, sans point
 * d'extension pour des fixers custom).
 *
 * Reprend l'équivalent exact de l'ancien pint.json :
 *  - preset "psr12" de Pint = @PSR12 + no_unused_imports (cf. resources/presets/psr12.php du binaire pint)
 *  - toutes les règles du "rules" de pint.json
 *  - même Finder (exclude)
 *
 * Usage :
 *   vendor/bin/php-cs-fixer fix --dry-run --diff
 *   vendor/bin/php-cs-fixer fix
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use App\CsFixer\SplitMethodAttributeArgsFixer;

$finder = Finder::create()
                ->in(__DIR__)
                ->exclude([
                    '.docker',
                    'node_modules',
                    'public/build',
                    'public/uploads',
                    'templates',
                    'tests',
                    'translations',
                    'var',
                    'vendor',
                ])
                ->notPath('config/reference.php')
                ->ignoreDotFiles(true)
                ->ignoreVCS(true);

return (new Config())
    ->registerCustomFixers([
        new SplitMethodAttributeArgsFixer(),
    ])
    ->setRules([
        // Preset "psr12" de Pint
        '@PSR12' => true,
        'no_unused_imports' => true,

        // Règles custom de l'ancien pint.json
        'declare_strict_types' => true,
        'array_syntax' => [
            'syntax' => 'short',
        ],
        'ordered_imports' => [
            'sort_algorithm' => 'length',
        ],
        'trailing_comma_in_multiline' => true,
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
        'no_extra_blank_lines' => true,
        'no_whitespace_in_blank_line' => true,
        'single_space_around_construct' => true,
        'types_spaces' => [
            'space' => 'single',
        ],
        'concat_space' => [
            'spacing' => 'one',
        ],
        'align_multiline_comment' => false,
        'single_quote' => true,
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
            ],
        ],
        'combine_consecutive_unsets' => true,
        'combine_consecutive_issets' => true,
        'type_declaration_spaces' => true,
        'statement_indentation' => [
            'stick_comment_to_next_continuous_control_statement' => true,
        ],

        // Règle custom : un argument par ligne sur les attributs de méthode 2+ args
        'CsFixer/split_method_attribute_args' => true
    ])
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setFinder($finder);
