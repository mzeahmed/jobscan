<?php

declare(strict_types=1);

namespace App\Profile\Model;

/**
 * Version de CV déclarée (ex. « backend PHP », « DevOps »). Le fichier n'est ni lu ni vérifié
 * ici : il reste local et ne quitte jamais la machine sans autorisation explicite.
 */
final readonly class CvVersion
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $path,
    ) {
    }
}
