<?php

declare(strict_types=1);

namespace App\Processor;

use App\DTO\JobDto;

interface JobProcessorInterface
{
    public function process(JobDto $dto, bool $dryRun = false): JobProcessingResult;

    /**
     * @param iterable<JobDto> $jobs
     * @return list<JobProcessingResult>
     */
    public function processBatch(iterable $jobs, bool $dryRun = false): array;
}
