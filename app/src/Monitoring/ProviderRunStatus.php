<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * Issue d'une exécution de provider.
 */
enum ProviderRunStatus: string
{
    /** Collecte réussie avec de nouvelles offres. */
    case Succeeded = 'succeeded';
    /** Collecte réussie, aucune nouvelle offre. */
    case NoNewJobs = 'no_new_jobs';
    /** Des erreurs sont survenues mais des offres ont tout de même été récupérées. */
    case Partial = 'partial';
    /** Aucune offre récupérée à cause d'erreurs, ou source injoignable. */
    case Failed = 'failed';
    /** Source désactivée par configuration. */
    case Disabled = 'disabled';

    public static function classify(bool $disabled, int $fetched, int $new, int $errors): self
    {
        return match (true) {
            $disabled => self::Disabled,
            $errors > 0 && $fetched === 0 => self::Failed,
            $errors > 0 => self::Partial,
            $new > 0 => self::Succeeded,
            default => self::NoNewJobs,
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded || $this === self::NoNewJobs;
    }
}
