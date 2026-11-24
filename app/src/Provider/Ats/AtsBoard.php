<?php

declare(strict_types=1);

namespace App\Provider\Ats;

/**
 * Un job board d'ATS à surveiller : identifiant de board (`token`, `site` ou nom de board selon
 * l'ATS) et, optionnellement, le nom d'entreprise à afficher.
 *
 * Le nom d'entreprise est saisi par l'utilisateur : les API ATS ne le renvoient pas toutes,
 * et il n'est jamais déduit de l'identifiant (ce serait une invention).
 */
final readonly class AtsBoard
{
    private function __construct(
        public string $token,
        public ?string $company,
    ) {
    }

    /**
     * Interprète `identifiant` ou `identifiant=Nom de l'entreprise`.
     *
     * L'identifiant est inséré dans le chemin d'une URL : seuls lettres, chiffres, `.`, `_` et `-`
     * sont acceptés, ce qui interdit tout détournement de l'hôte ou du chemin (SSRF).
     */
    public static function tryFromConfig(?string $entry): ?self
    {
        $entry = trim((string) $entry);
        if ($entry === '') {
            return null;
        }

        [$token, $company] = array_pad(explode('=', $entry, 2), 2, null);
        $token = trim((string) $token);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $token) !== 1) {
            return null;
        }

        $company = trim((string) $company);

        return new self($token, $company === '' ? null : mb_substr($company, 0, 255));
    }
}
