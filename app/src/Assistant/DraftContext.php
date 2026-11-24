<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Tout ce dont le rédacteur a besoin, et rien d'autre : jamais le texte brut de l'annonce, ni le nom, l'email,
 * le téléphone ou les prétentions salariales du candidat.
 */
final readonly class DraftContext
{
    /**
     * @param list<string> $facts Faits de parcours littéraux du profil (arguments du matching) : jamais de préférences ni de salaire
     * @param list<string> $missingSkills Compétences demandées que le candidat ne déclare pas : jamais à revendiquer
     * @param list<string> $vocabulary Termes techniques connus, pour détecter une compétence revendiquée à tort
     */
    public function __construct(
        public string $jobTitle,
        public ?string $company,
        public array $facts,
        public array $missingSkills,
        public array $vocabulary,
        public ?string $recruiterName = null,
    ) {
    }

    public function allowedText(): string
    {
        return implode("\n", [...$this->facts, $this->jobTitle, (string) $this->company, (string) $this->recruiterName]);
    }
}
