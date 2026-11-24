<?php

declare(strict_types=1);

namespace App\Assistant;

use App\AI\Extraction\TextMatcher;
use App\AI\Extraction\PromptInjectionDetector;

/**
 * Vérifie qu'un texte rédigé par un LLM n'affirme rien qui ne figure pas dans les faits fournis.
 *
 * Le LLM n'est jamais cru sur parole : tout chiffre, toute compétence connue, tout lien ou adresse absents des faits sont
 * refusés, ainsi que toute compétence que le candidat ne déclare pas. Un refus renvoie au brouillon sans LLM.
 * La garde ne prouve pas l'exactitude d'une phrase : la relecture de l'utilisateur reste indispensable.
 */
final readonly class DraftGuard
{
    private const int MAX_SUBJECT = 120;
    private const int MAX_BODY = 2000;

    public function __construct(private PromptInjectionDetector $injection)
    {
    }

    /**
     * @return list<string> Motifs de refus ; vide si le texte est acceptable
     */
    public function violations(string $subject, string $body, DraftContext $context): array
    {
        $violations = [];
        $subject = trim($subject);
        $body = trim($body);

        if ($subject === '' || $body === '') {
            return ['objet ou corps vide'];
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT || mb_strlen($body) > self::MAX_BODY) {
            $violations[] = 'texte trop long';
        }

        $text = $subject . "\n" . $body;

        if (preg_match('~https?://|www\.|[\w.+-]+@[\w-]+\.[\w.]+~iu', $text) === 1) {
            $violations[] = 'lien ou adresse email non fournis';
        }
        if ($this->injection->isSuspicious($text)) {
            $violations[] = 'consigne détectée dans le texte';
        }

        $allowed = $context->allowedText();
        $numbers = [];
        preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $numbers);
        foreach (array_unique($numbers[0]) as $number) {
            if (preg_match('/(?<![\d.,])' . preg_quote($number, '/') . '(?![\d])/u', $allowed) !== 1) {
                $violations[] = sprintf('chiffre « %s » absent des faits', $number);
            }
        }

        $matcher = new TextMatcher($text);
        $claimable = new TextMatcher($allowed);
        foreach ($context->vocabulary as $term) {
            if ($matcher->containsWord($term) && !$claimable->containsWord($term)) {
                $violations[] = sprintf('compétence « %s » absente des faits', $term);
            }
        }
        foreach ($context->missingSkills as $term) {
            if ($matcher->containsWord($term)) {
                $violations[] = sprintf('compétence non déclarée « %s » revendiquée', $term);
            }
        }

        return array_values(array_unique($violations));
    }
}
