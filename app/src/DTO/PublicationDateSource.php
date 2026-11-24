<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Origine de la date de publication connue d'une offre.
 */
enum PublicationDateSource: string
{
    /** Champ de date structuré d'une API officielle. */
    case Api = 'api';
    /** Date d'un flux RSS/Atom (peut être une date de mise à jour). */
    case Feed = 'feed';
    /** Date structurée renvoyée par un moteur de recherche. */
    case Search = 'search';
    /** Date déduite d'un texte libre (titre, extrait). */
    case Text = 'text';
    case Unknown = 'unknown';
}
