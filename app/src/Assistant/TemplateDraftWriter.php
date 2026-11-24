<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Matching\ArgumentBuilder;

/**
 * Rédige le brouillon sans LLM, uniquement à partir de faits déclarés par le candidat, reformulés mot pour mot.
 *
 * C'est le brouillon de référence et le repli quand le LLM est absent, interdit ou refusé : il ne peut rien inventer.
 * Les compétences manquantes n'y figurent jamais.
 */
final class TemplateDraftWriter
{
    private const array REPHRASE = [
        ArgumentBuilder::PREFIX_REQUIRED => 'Je dispose des compétences que vous demandez : ',
        ArgumentBuilder::PREFIX_NICE => 'Je dispose aussi de compétences que votre annonce apprécie ou cite : ',
        ArgumentBuilder::PREFIX_LANGUAGE => 'Langue demandée couverte : ',
    ];

    public function write(DraftContext $context): Draft
    {
        $subject = 'Candidature — ' . $context->jobTitle;
        $greeting = $context->recruiterName === null ? 'Bonjour,' : 'Bonjour ' . $context->recruiterName . ',';
        $at = $context->company === null ? '' : ' chez ' . $context->company;

        $lines = [$greeting, '', sprintf('Je souhaite candidater au poste « %s »%s.', $context->jobTitle, $at)];

        $points = array_values(array_filter(array_map($this->sentence(...), $context->facts)));
        if ($points !== []) {
            $lines[] = '';
            $lines[] = 'Voici ce qui, dans mon parcours, correspond à votre annonce :';
            foreach ($points as $point) {
                $lines[] = '- ' . $point;
            }
        }

        array_push($lines, '', 'Je serais disponible pour en échanger à votre convenance.', '', 'Cordialement,');

        return new Draft($subject, implode("\n", $lines), Draft::SOURCE_TEMPLATE, []);
    }

    private function sentence(string $fact): string
    {
        foreach (self::REPHRASE as $prefix => $replacement) {
            if (str_starts_with($fact, $prefix)) {
                return $replacement . substr($fact, strlen($prefix));
            }
        }
        if (str_starts_with($fact, ArgumentBuilder::PREFIX_EXPERIENCE)) {
            return 'Expérience : ' . substr($fact, strlen(ArgumentBuilder::PREFIX_EXPERIENCE));
        }
        if (str_starts_with($fact, ArgumentBuilder::PREFIX_PROJECT)) {
            return 'Projet : ' . substr($fact, strlen(ArgumentBuilder::PREFIX_PROJECT));
        }

        return $fact;
    }
}
