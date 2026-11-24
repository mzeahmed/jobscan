<?php

declare(strict_types=1);

namespace App\Email;

enum ProcessedEmailStatus: string
{
    /** Au moins une offre extraite et ingérée avec succès. */
    case Ingested = 'ingested';
    /** Email reconnu mais ne contenant aucune offre exploitable. */
    case NoOffers = 'no_offers';
    /** L'analyse de l'email a échoué (structure inattendue) : à investiguer. */
    case ParseFailed = 'parse_failed';
    /** Expéditeur inconnu de tous les parsers. */
    case Unsupported = 'unsupported';
}
