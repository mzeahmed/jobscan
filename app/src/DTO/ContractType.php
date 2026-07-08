<?php

declare(strict_types=1);

namespace App\DTO;

enum ContractType: string
{
    case Freelance = 'freelance';
    case Cdi = 'cdi';
    case Unknown = 'unknown';
}
