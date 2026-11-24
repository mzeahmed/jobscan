<?php

declare(strict_types=1);

namespace App\Tracking;

/**
 * Canal par lequel la candidature a été envoyée.
 */
enum ApplicationChannel: string
{
    case Email = 'email';
    case Linkedin = 'linkedin';
    /** Formulaire du site de l'entreprise ou de la plateforme d'emploi. */
    case Platform = 'platform';
    /** Cooptation ou recommandation. */
    case Referral = 'referral';
    case Phone = 'phone';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Linkedin => 'LinkedIn',
            self::Platform => 'Site ou plateforme',
            self::Referral => 'Cooptation',
            self::Phone => 'Téléphone',
            self::Other => 'Autre',
        };
    }
}
