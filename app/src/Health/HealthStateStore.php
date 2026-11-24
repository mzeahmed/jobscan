<?php

declare(strict_types=1);

namespace App\Health;

/**
 * Mémorise l'empreinte des problèmes déjà signalés, pour n'alerter qu'à chaque changement.
 */
final readonly class HealthStateStore
{
    public function __construct(private string $file)
    {
    }

    public function read(): ?string
    {
        return is_file($this->file) ? trim((string) file_get_contents($this->file)) : null;
    }

    public function write(string $signature): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }

        $temporary = $this->file . '.tmp';
        file_put_contents($temporary, $signature);
        rename($temporary, $this->file);
    }
}
