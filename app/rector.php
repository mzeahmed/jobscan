<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Configuration alignée sur phpstan.neon (mêmes chemins analysés, même
// version PHP cible) pour que Rector et PHPStan restent cohérents.
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/bin',
        __DIR__ . '/config',
        __DIR__ . '/public',
        __DIR__ . '/src',
    ])
    ->withSkip([
        __DIR__ . '/vendor',
        __DIR__ . '/var',
    ])
    // php: ">=8.4" dans composer.json
    ->withPhpVersion(80400)
    ->withPhpSets(php84: true)
    // Réutilise la config PHPStan (mêmes chemins/niveau) pour affiner les règles Rector
    ->withPHPStanConfigs([__DIR__ . '/phpstan.neon'])
    ->withPreparedSets(deadCode: true);
