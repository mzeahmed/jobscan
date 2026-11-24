<?php

declare(strict_types=1);

namespace App\Matching;

enum CriterionStatus: string
{
    case Match = 'match';
    case Partial = 'partial';
    case Mismatch = 'mismatch';
    /** L'annonce ou le profil ne fournit pas l'information : le critère n'est pas évalué, ni compté comme un échec. */
    case Unknown = 'unknown';
    /** Le critère ne s'applique pas (rien à comparer). */
    case NotApplicable = 'not_applicable';

    /** Le critère a pu être évalué et entre dans le score. */
    public function isKnown(): bool
    {
        return $this === self::Match || $this === self::Partial || $this === self::Mismatch;
    }
}
