<?php

declare(strict_types=1);

namespace App\Matching;

use App\Entity\Job;
use App\AI\Extraction\JobRequirements;

/**
 * Ce que le matching sait d'une offre : son titre, sa description, son lieu brut et ses exigences extraites.
 */
final readonly class JobFacts
{
    public function __construct(
        public string $title,
        public string $description,
        public ?string $location,
        public JobRequirements $requirements,
    ) {
    }

    /** `null` tant que l'offre n'a pas d'exigences extraites : elle n'est pas appariable. */
    public static function fromJob(Job $job): ?self
    {
        $requirements = $job->getRequirements();

        return $requirements === null
            ? null
            : new self((string) $job->getTitle(), (string) $job->getDescription(), $job->getLocation(), $requirements);
    }

    /** Texte des lieux de l'offre : lieu brut de la source et lieux extraits. */
    public function locationText(): string
    {
        return implode(' ; ', array_filter([$this->location, ...$this->requirements->locations], static fn (?string $value): bool => $value !== null && $value !== ''));
    }
}
