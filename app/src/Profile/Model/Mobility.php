<?php

declare(strict_types=1);

namespace App\Profile\Model;

enum Mobility: string
{
    case Local = 'local';
    case Regional = 'regional';
    case National = 'national';
    case International = 'international';
}
