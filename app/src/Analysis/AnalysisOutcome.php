<?php

declare(strict_types=1);

namespace App\Analysis;

final readonly class AnalysisOutcome
{
    private function __construct(
        public bool $succeeded,
        public ?int $score,
        public bool $usedFallback,
        public ?string $error,
    ) {
    }

    public static function analyzed(int $score, bool $usedFallback): self
    {
        return new self(true, $score, $usedFallback, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, false, $error);
    }
}
