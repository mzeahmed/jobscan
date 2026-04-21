<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\DTO\JobDTO;

interface JobProviderInterface
{
    /**
     * Récupère les offres d'emploi depuis la source.
     *
     * @return JobDTO[]
     */
    public function fetch(): array;
}
