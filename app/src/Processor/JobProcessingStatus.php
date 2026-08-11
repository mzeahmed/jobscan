<?php

declare(strict_types=1);

namespace App\Processor;

enum JobProcessingStatus: string
{
    case Saved = 'saved';
    case Filtered = 'filtered';
    case TooOld = 'too_old';
    case Duplicate = 'duplicate';
    case LowPrescore = 'low_prescore';
    case DryRun = 'dry_run';
    case Failed = 'failed';
}
