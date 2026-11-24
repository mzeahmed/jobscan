<?php

declare(strict_types=1);

namespace App\Profile\Model;

/**
 * Niveau de maîtrise déclaré d'une compétence. Optionnel : une compétence sans niveau est
 * simplement « déclarée », sans que le niveau soit supposé.
 */
enum SkillLevel: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
    case Expert = 'expert';
}
