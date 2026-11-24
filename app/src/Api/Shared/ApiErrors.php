<?php

declare(strict_types=1);

namespace App\Api\Shared;

/**
 * Code et message par défaut d'une erreur d'API, d'après son statut HTTP.
 *
 * Les messages sont en français (l'application est monolingue) et ne contiennent jamais de détail technique :
 * ils sont destinés à être affichés tels quels par le front.
 */
final class ApiErrors
{
    /** @var array<int, array{string, string}> */
    private const array BY_STATUS = [
        400 => ['bad_request', 'Requête invalide.'],
        401 => ['unauthorized', 'Authentification requise.'],
        403 => ['forbidden', 'Accès refusé.'],
        404 => ['not_found', 'Ressource introuvable.'],
        405 => ['method_not_allowed', 'Méthode non autorisée.'],
        406 => ['not_acceptable', 'Format de réponse non pris en charge.'],
        409 => ['conflict', 'Conflit avec l\'état actuel de la ressource.'],
        413 => ['payload_too_large', 'Requête trop volumineuse.'],
        415 => ['unsupported_media_type', 'Format de contenu non pris en charge.'],
        422 => ['unprocessable', 'Données invalides.'],
        429 => ['too_many_requests', 'Trop de requêtes : réessayez plus tard.'],
        500 => ['internal_error', 'Erreur interne du serveur.'],
        502 => ['bad_gateway', 'Réponse invalide d\'un service distant.'],
        503 => ['service_unavailable', 'Service momentanément indisponible.'],
        504 => ['gateway_timeout', 'Un service distant n\'a pas répondu à temps.'],
    ];

    public static function code(int $status): string
    {
        return self::BY_STATUS[$status][0] ?? ($status >= 500 ? 'server_error' : 'client_error');
    }

    public static function message(int $status): string
    {
        return self::BY_STATUS[$status][1] ?? ($status >= 500 ? 'Erreur du serveur.' : 'Requête refusée.');
    }
}
