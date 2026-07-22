<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Charge le namespace `jobscan:` et l'expose sous forme de paramètres de container
 * (`jobscan.llm.provider`, `jobscan.llm.ollama.base_url`, ...) consommés dans
 * `config/services.yaml`.
 */
final class JobscanExtension extends Extension
{
    public function getConfiguration(array $config, ContainerBuilder $container): ConfigurationInterface
    {
        return new Configuration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);

        foreach ($config['llm'] as $key => $value) {
            if (\is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    $container->setParameter("jobscan.llm.{$key}.{$subKey}", $subValue);
                }

                continue;
            }

            $container->setParameter("jobscan.llm.{$key}", $value);
        }
    }
}
