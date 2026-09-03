<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use App\Provider\SearxNoiseFilter;
use PHPUnit\Framework\Attributes\DataProvider;

final class SearxNoiseFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function clearlyIrrelevantResults(): iterable
    {
        yield 'documentation officielle PHP' => [
            'PHP: array_map - Manual',
            'https://www.php.net/manual/en/function.array-map.php',
            'Applies the callback to the elements of the given arrays',
        ];
        yield 'site de tutoriels' => [
            'Symfony Tutorial for Beginners',
            'https://openclassrooms.com/fr/courses/symfony',
            'Apprenez à créer une application Symfony pas à pas',
        ];
        yield 'article Wikipedia' => [
            'PHP (langage)',
            'https://fr.wikipedia.org/wiki/PHP',
            'PHP est un langage de programmation libre',
        ];
        yield 'vidéo YouTube' => [
            'Créer une API REST en PHP',
            'https://www.youtube.com/watch?v=abcdef',
            'Dans cette vidéo nous construisons une API',
        ];
        yield 'formation malgré le mot développeur' => [
            'Formation développeur web full stack',
            'https://example.com/formation-developpeur-web',
            'Devenez développeur en 6 mois',
        ];
        yield 'contenu sans aucun signal emploi' => [
            'Recette de tarte aux pommes',
            'https://cuisine.example/tarte-pommes',
            'Préparez une pâte brisée et garnissez de pommes',
        ];
        yield 'tout vide' => ['', '', ''];
    }

    #[DataProvider('clearlyIrrelevantResults')]
    public function testRejectsClearlyIrrelevantResults(string $title, string $url, string $description): void
    {
        self::assertTrue(new SearxNoiseFilter()->isClearlyIrrelevant($title, $url, $description));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function relevantResults(): iterable
    {
        yield 'annonce sur un job board' => [
            'Développeur PHP Symfony (H/F)',
            'https://www.welcometothejungle.com/fr/jobs/developpeur-php',
            'CDI à Paris, équipe produit',
        ];
        yield 'mission sur une plateforme freelance' => [
            'Mission freelance Symfony',
            'https://www.malt.fr/profile/consultant-symfony',
            'Remote, démarrage ASAP',
        ];
        yield 'offre anglophone en remote' => [
            'Senior Backend Engineer',
            'https://remoteok.com/remote-jobs/12345',
            'We are hiring a remote contractor',
        ];
        yield 'page carrières entreprise' => [
            'Careers - Acme',
            'https://acme.example/careers',
            'Rejoignez notre équipe : poste de développeur backend',
        ];
        yield 'liste Indeed' => [
            "Offres d'emploi Développeur PHP",
            'https://fr.indeed.com/emplois?q=php',
            'Plus de 100 offres correspondent à votre recherche',
        ];
        yield 'titre vide mais URL et description parlantes' => [
            '',
            'https://weworkremotely.com/remote-jobs/php-engineer',
            'PHP developer wanted, full remote',
        ];
        yield 'URL vide mais signaux dans le texte' => [
            'Mission freelance PHP',
            '',
            'Télétravail, régie possible',
        ];
        yield 'titre avec caractères unicode' => [
            'Développeur PHP — Île-de-France 🚀',
            'https://jobs.example/1',
            'CDI, télétravail partiel',
        ];
    }

    #[DataProvider('relevantResults')]
    public function testKeepsRelevantResults(string $title, string $url, string $description): void
    {
        self::assertFalse(new SearxNoiseFilter()->isClearlyIrrelevant($title, $url, $description));
    }

    public function testHonoursCustomLists(): void
    {
        $filter = new SearxNoiseFilter(blockedPatterns: ['sponsorisé'], jobSignals: ['recrutement']);

        self::assertTrue(
            $filter->isClearlyIrrelevant('Contenu sponsorisé recrutement', 'https://x.example', ''),
            'Un pattern bloquant prime sur la présence d\'un signal emploi.',
        );
        self::assertFalse($filter->isClearlyIrrelevant('Campagne de recrutement', 'https://x.example', ''));
        self::assertTrue($filter->isClearlyIrrelevant('Une actualité quelconque', 'https://x.example', ''));
    }

    public function testIgnoresEmptyConfiguredEntries(): void
    {
        $filter = new SearxNoiseFilter(blockedPatterns: ['', 'wikipedia'], jobSignals: ['', 'emploi']);

        self::assertFalse($filter->isClearlyIrrelevant('Offre emploi PHP', 'https://jobs.example', ''));
        self::assertTrue($filter->isClearlyIrrelevant('Article wikipedia', 'https://x.example', ''));
    }
}
