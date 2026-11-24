<?php

declare(strict_types=1);

namespace App\Profile\Model;

final readonly class Availability
{
    /** @param string|null $from Date de disponibilité (`AAAA-MM-JJ`) */
    public function __construct(
        public ?string $from,
        public ?int $noticePeriodDays,
        public ?string $comment,
    ) {
    }
}
