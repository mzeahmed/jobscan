<?php

declare(strict_types=1);

namespace App\Cv;

use App\Profile\Model\CvVersion;

/**
 * Vérifie qu'un fichier de CV déclaré existe, sans jamais en lire le contenu : le CV est une donnée
 * personnelle qui ne quitte pas la machine.
 */
final readonly class CvInspector
{
    public function __construct(private ?string $homeDirectory = null)
    {
    }

    public function inspect(CvVersion $cv): CvFileStatus
    {
        if ($cv->path === null || trim($cv->path) === '') {
            return CvFileStatus::notDeclared();
        }

        $path = $this->expand(trim($cv->path));

        if (!is_file($path)) {
            return CvFileStatus::problem(CvFileStatus::MISSING, $path);
        }

        if (!is_readable($path)) {
            return CvFileStatus::problem(CvFileStatus::UNREADABLE, $path);
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            return CvFileStatus::problem(CvFileStatus::EMPTY, $path);
        }

        $modified = filemtime($path);

        return CvFileStatus::ok($path, $size, new \DateTimeImmutable('@' . ($modified === false ? 0 : $modified)));
    }

    private function expand(string $path): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            $home = $this->homeDirectory ?? (is_string(getenv('HOME')) ? getenv('HOME') : '');

            return $home === '' ? $path : $home . substr($path, 1);
        }

        return $path;
    }
}
