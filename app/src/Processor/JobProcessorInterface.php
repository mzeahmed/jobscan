<?php

declare(strict_types=1);

namespace App\Processor;

use App\DTO\JobDto;

interface JobProcessorInterface
{
    public function process(JobDto $dto, bool $dryRun = false): JobProcessingResult;
}
