<?php

declare(strict_types=1);

namespace App\Email\Parser;

use App\DTO\JobDto;
use App\Email\EmailMessage;

/**
 * Extrait les offres d'un format d'email d'alerte. Ajouter une plateforme = ajouter un parser
 * (autoconfiguré, tag `app.email_parser`) ; le provider n'a pas à changer.
 */
interface EmailParserInterface
{
    /** Identifiant court de la plateforme (`linkedin`, `indeed`…), repris dans la source des offres. */
    public function platform(): string;

    public function supports(EmailMessage $email): bool;

    /**
     * Ne fabrique jamais d'information : un champ absent de l'email reste `null`.
     *
     * @return list<JobDto>
     * @throws EmailParsingException si la structure de l'email est inexploitable
     */
    public function parse(EmailMessage $email): array;
}
