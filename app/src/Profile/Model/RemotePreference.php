<?php

declare(strict_types=1);

namespace App\Profile\Model;

/**
 * Mode de travail : utilisé pour les modes acceptés par le candidat et par un profil de recherche.
 */
enum RemotePreference: string
{
    case Onsite = 'onsite';
    case Hybrid = 'hybrid';
    case Remote = 'remote';
}
