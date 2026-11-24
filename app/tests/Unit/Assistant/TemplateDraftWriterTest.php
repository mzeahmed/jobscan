<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assistant;

use App\Assistant\Draft;
use App\Assistant\DraftContext;
use PHPUnit\Framework\TestCase;
use App\Assistant\TemplateDraftWriter;

final class TemplateDraftWriterTest extends TestCase
{
    public function testBuildsADraftFromTheFactsOnly(): void
    {
        $context = new DraftContext(
            'Développeur PHP',
            'Exemple SAS',
            [
                'Compétences exigées déclarées : PHP (expert, 8 an(s)).',
                'Compétences appréciées ou citées déclarées : Docker.',
                'Expérience pertinente : Développeur chez Acme (PHP).',
                'Projet pertinent : Outil interne (Symfony).',
                'Langue exigée couverte : Anglais (B2).',
            ],
            ['Kubernetes'],
            [],
        );

        $draft = new TemplateDraftWriter()->write($context);

        self::assertSame(Draft::SOURCE_TEMPLATE, $draft->source);
        self::assertSame('Candidature — Développeur PHP', $draft->subject);
        self::assertStringStartsWith("Bonjour,\n", $draft->body);
        self::assertStringContainsString('poste « Développeur PHP » chez Exemple SAS', $draft->body);
        self::assertStringContainsString('- Je dispose des compétences que vous demandez : PHP (expert, 8 an(s)).', $draft->body);
        self::assertStringContainsString('- Expérience : Développeur chez Acme (PHP).', $draft->body);
        self::assertStringContainsString('- Projet : Outil interne (Symfony).', $draft->body);
        self::assertStringContainsString('- Langue demandée couverte : Anglais (B2).', $draft->body);
        self::assertStringEndsWith('Cordialement,', $draft->body);
        self::assertStringNotContainsString('Kubernetes', $draft->body);
    }

    public function testWithoutCompanyOrFactsNothingIsInvented(): void
    {
        $draft = new TemplateDraftWriter()->write(new DraftContext('Dév', null, [], [], []));

        self::assertStringContainsString('poste « Dév ».', $draft->body);
        self::assertStringNotContainsString('chez', $draft->body);
        self::assertStringNotContainsString('correspond à votre annonce', $draft->body);
    }

    public function testTheRecruiterNameIsOnlyUsedWhenProvided(): void
    {
        $writer = new TemplateDraftWriter();

        self::assertStringStartsWith('Bonjour Camille Martin,', $writer->write(new DraftContext('Dév', null, [], [], [], 'Camille Martin'))->body);
        self::assertStringStartsWith('Bonjour,', $writer->write(new DraftContext('Dév', null, [], [], []))->body);
    }
}
