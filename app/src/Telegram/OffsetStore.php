<?php

declare(strict_types=1);

namespace App\Telegram;

/**
 * Mémorise la dernière mise à jour Telegram traitée, pour ne pas la rejouer après un redémarrage.
 */
final readonly class OffsetStore
{
    public function __construct(private string $file)
    {
    }

    public function read(): int
    {
        $value = is_file($this->file) ? trim((string) file_get_contents($this->file)) : '';

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : 0;
    }

    public function write(int $offset): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        $temporary = $this->file . '.tmp';
        file_put_contents($temporary, (string) $offset);
        rename($temporary, $this->file);
    }
}
