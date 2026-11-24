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
    /**
     * Ancienneté brute (« 5 h », « 3 j »), ou `null` si la publication est inconnue ou dans le futur.
     */
    public static function ago(Job $job, \DateTimeImmutable $now): ?string
    {
        $published = $job->getPublishedAt();
        if ($published === null || $job->getPublicationDateConfidence() === PublicationDateConfidence::Unknown) {
            return null;
        }

        $seconds = $now->getTimestamp() - $published->getTimestamp();

        return match (true) {
            $seconds < 0 => null,
            $seconds < 3600 * 48 => sprintf('%d h', intdiv($seconds, 3600)),
            default => sprintf('%d j', intdiv($seconds, 86400)),
        };
    }

    public static function describe(Job $job, \DateTimeImmutable $now): string
    {
        $published = $job->getPublishedAt();
        if ($published === null || $job->getPublicationDateConfidence() === PublicationDateConfidence::Unknown) {
            return 'publication inconnue';
        }

        $age = self::ago($job, $now) ?? 'date future';

        return $job->getPublicationDateConfidence() === PublicationDateConfidence::Low ? $age . ' (date peu fiable)' : $age;
    }
}
