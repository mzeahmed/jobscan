<?php

declare(strict_types=1);

namespace App\Processor;

final readonly class JobProcessingResult
{
    public function __construct(
        public JobProcessingStatus $status,
        public ?int $score = null,
        public bool $usedFallback = false,
        public bool $notified = false,
        public ?string $error = null,
    ) {
    }

    public static function failed(string $error): self
    {
        return new self(JobProcessingStatus::Failed, error: $error);
    }
}
