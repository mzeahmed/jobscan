<?php

declare(strict_types=1);

namespace App\DTO;

enum Seniority: string
{
    case Junior = 'junior';
    case Mid = 'mid';
    case Senior = 'senior';
    case Unknown = 'unknown';
}
