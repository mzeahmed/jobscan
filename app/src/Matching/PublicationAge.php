<?php

declare(strict_types=1);

namespace App\Matching;

use App\Entity\Job;
use App\DTO\PublicationDateConfidence;

/**
 * Ancienneté lisible d'une offre, en distinguant publication connue et inconnue.
 */
final class PublicationAge
{
    public static function describe(Job $job, \DateTimeImmutable $now): string
    {
        $published = $job->getPublishedAt();
        if ($published === null || $job->getPublicationDateConfidence() === PublicationDateConfidence::Unknown) {
            return 'publication inconnue';
        }

        $seconds = $now->getTimestamp() - $published->getTimestamp();
        $age = match (true) {
            $seconds < 0 => 'date future',
            $seconds < 3600 * 48 => sprintf('%d h', max(0, intdiv($seconds, 3600))),
            default => sprintf('%d j', intdiv($seconds, 86400)),
        };

        return $job->getPublicationDateConfidence() === PublicationDateConfidence::Low ? $age . ' (date peu fiable)' : $age;
    }
}
