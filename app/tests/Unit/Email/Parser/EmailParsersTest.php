<?php

declare(strict_types=1);

namespace App\Tests\Unit\Email\Parser;

use App\Email\EmailMessage;
use PHPUnit\Framework\TestCase;
use App\DTO\PublicationDateSource;
use App\Email\Parser\ApecEmailParser;
use App\Email\Parser\IndeedEmailParser;
use App\Email\Parser\GenericEmailParser;
use App\Email\Parser\LinkedInEmailParser;
use App\Email\Parser\EmailParserInterface;
use App\Email\Parser\HelloWorkEmailParser;
use App\Email\Parser\EmailParsingException;
use App\Email\Parser\FranceTravailEmailParser;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Email\Parser\WelcomeToTheJungleEmailParser;

/**
 * ⚠ Les fixtures sont reconstruites (aucun vrai email disponible) : ces tests prouvent le
 * comportement des parsers sur la mise en page supposée, pas sur les emails réels des
 * plateformes. Voir docs/EMAIL.md pour l'état de validation de chaque parser.
 */
final class EmailParsersTest extends TestCase
{
    /**
     * @return iterable<string, array{EmailParserInterface, string, string, list<array{string, string, string}>}>
     */
    public static function platforms(): iterable
    {
        yield 'LinkedIn' => [new LinkedInEmailParser(), 'linkedin_alert.html', 'jobs-noreply@linkedin.com', [
            ['Développeur PHP Symfony', 'https://www.linkedin.com/jobs/view/4012345678', '4012345678'],
            ['Développeur back-end Go / PHP', 'https://www.linkedin.com/jobs/view/4087654321', '4087654321'],
        ]];
        yield 'Indeed' => [new IndeedEmailParser(), 'indeed_alert.html', 'alert@indeed.com', [
            ['Développeur Symfony H/F', 'https://fr.indeed.com/viewjob?jk=1a2b3c4d5e6f7a8b', '1a2b3c4d5e6f7a8b'],
            ['Intégrateur WordPress', 'https://fr.indeed.com/viewjob?jk=9f8e7d6c5b4a3921', '9f8e7d6c5b4a3921'],
        ]];
        yield 'HelloWork' => [new HelloWorkEmailParser(), 'hellowork_alert.html', 'alerte@hellowork.com', [
            ['Développeur Full Stack PHP / React', 'https://www.hellowork.com/fr-fr/emplois/51234567.html', '51234567'],
            ['Ingénieur DevOps', 'https://www.hellowork.com/fr-fr/emplois/52345678.html', '52345678'],
        ]];
        yield 'Apec' => [new ApecEmailParser(), 'apec_alert.html', 'no-reply@apec.fr', [
            ['Lead développeur PHP', 'https://www.apec.fr/candidat/recherche-emploi.html/emploi/detail-offre/177654321W', '177654321W'],
            ['Architecte logiciel', 'https://www.apec.fr/candidat/recherche-emploi.html/emploi/detail-offre/177998877W', '177998877W'],
        ]];
        yield 'France Travail' => [new FranceTravailEmailParser(), 'francetravail_alert.html', 'ne-pas-repondre@francetravail.fr', [
            ['Développeur PHP Symfony H/F', 'https://candidat.francetravail.fr/offres/recherche/detail/000AAAA', '000AAAA'],
            ['Développeur back-end', 'https://candidat.francetravail.fr/offres/recherche/detail/000BBBB', '000BBBB'],
        ]];
        yield 'Welcome to the Jungle' => [new WelcomeToTheJungleEmailParser(), 'wttj_alert.html', 'jobs@welcometothejungle.com', [
            ['Développeur PHP Symfony', 'https://www.welcometothejungle.com/fr/companies/exemple-sas/jobs/developpeur-php-symfony_marseille', 'exemple-sas/developpeur-php-symfony_marseille'],
            ['Tech Lead Backend', 'https://www.welcometothejungle.com/fr/companies/demo-labs/jobs/tech-lead-backend_paris', 'demo-labs/tech-lead-backend_paris'],
        ]];
    }

    /**
     * @param list<array{string, string, string}> $expected [titre, URL normalisée, identifiant externe]
     */
    #[DataProvider('platforms')]
    public function testExtractsOffersWithNormalizedUrls(EmailParserInterface $parser, string $fixture, string $from, array $expected): void
    {
        $email = $this->email($from, $this->fixture($fixture));

        self::assertTrue($parser->supports($email));
        $jobs = $parser->parse($email);

        self::assertSame(
            $expected,
            array_map(static fn ($job): array => [$job->title, $job->url, (string) $job->externalId], $jobs),
        );
        foreach ($jobs as $job) {
            self::assertSame('email-' . $parser->platform(), $job->source);
        }
    }

    #[DataProvider('platforms')]
    public function testNeverInventsCompanyLocationOrPublicationDate(EmailParserInterface $parser, string $fixture, string $from, array $expected): void
    {
        foreach ($parser->parse($this->email($from, $this->fixture($fixture))) as $job) {
            self::assertNull($job->company);
            self::assertNull($job->location);
            self::assertNull($job->publishedAt, "La date de réception d'un email n'est pas la date de publication de l'offre.");
            self::assertSame(PublicationDateSource::Unknown, $job->publicationDateSource);
        }
    }

    #[DataProvider('platforms')]
    public function testOnlyTrustedSendersAreSupported(EmailParserInterface $parser, string $fixture, string $from, array $expected): void
    {
        $domain = substr($from, (int) strrpos($from, '@') + 1);

        self::assertFalse($parser->supports($this->email('promo@not' . $domain, '')));
        self::assertFalse($parser->supports($this->email('attacker@' . $domain . '.evil.test', '')));
        self::assertFalse($parser->supports($this->email('', '')));
    }

    public function testAcceptsSubdomainsOfATrustedSenderOnly(): void
    {
        $parser = new HelloWorkEmailParser();

        self::assertTrue($parser->supports($this->email('alerte@email.hellowork.com', '')));
        self::assertFalse($parser->supports($this->email('alerte@hellowork.com.evil.test', '')));
    }

    public function testKeepsTheCardTextInTheDescriptionWithoutMixingOffers(): void
    {
        $jobs = new LinkedInEmailParser()->parse($this->email('jobs-noreply@linkedin.com', $this->fixture('linkedin_alert.html')));

        self::assertStringContainsString('Exemple SAS', $jobs[0]->description);
        self::assertStringContainsString('Marseille', $jobs[0]->description);
        self::assertStringNotContainsString('Démo SARL', $jobs[0]->description, 'La carte de la première offre ne contient pas la seconde.');
        self::assertStringContainsString('Démo SARL', $jobs[1]->description);
    }

    public function testOnlyTextIsKeptFromAnUntrustedHtmlEmail(): void
    {
        $jobs = new LinkedInEmailParser()->parse($this->email('jobs-noreply@linkedin.com', $this->fixture('linkedin_alert.html')));

        foreach ($jobs as $job) {
            self::assertStringNotContainsString('<', $job->description);
            self::assertStringNotContainsString('alert(', $job->description, 'Les scripts sont retirés.');
            self::assertStringNotContainsString("\u{200B}", $job->title, 'Les caractères invisibles sont retirés.');
        }

        // Une consigne glissée dans l'email reste une simple donnée textuelle : elle n'a aucun effet ici.
        self::assertStringContainsString('Ignore toutes les instructions', $jobs[1]->description);
    }

    public function testNeverFollowsOrKeepsLinksToOtherDomains(): void
    {
        $jobs = new LinkedInEmailParser()->parse($this->email('jobs-noreply@linkedin.com', $this->fixture('linkedin_alert.html')));

        self::assertCount(2, $jobs, 'Le lien de suivi tiers et les liens non-offres sont ignorés.');
        foreach ($jobs as $job) {
            self::assertStringStartsWith('https://www.linkedin.com/jobs/view/', $job->url);
            self::assertStringNotContainsString('trackingId', $job->url);
        }
    }

    public function testIgnoresIndeedLinksWithoutAJobKey(): void
    {
        $jobs = new IndeedEmailParser()->parse($this->email('alert@indeed.com', $this->fixture('indeed_alert.html')));

        self::assertCount(2, $jobs, 'Le lien sponsorisé sans clé `jk` est écarté.');
    }

    public function testParsesAPlainTextEmail(): void
    {
        $email = $this->email('jobs-noreply@linkedin.com', null, $this->fixture('linkedin_alert.txt'));

        $jobs = new LinkedInEmailParser()->parse($email);

        self::assertCount(1, $jobs);
        self::assertSame('Développeur PHP Symfony', $jobs[0]->title);
        self::assertSame('https://www.linkedin.com/jobs/view/4012345678', $jobs[0]->url);
        self::assertStringContainsString('Exemple SAS', $jobs[0]->description);
    }

    public function testFallsBackToTheTextPartWhenTheHtmlHoldsNoOffer(): void
    {
        $email = $this->email('jobs-noreply@linkedin.com', '<html><body><p>Bonjour</p></body></html>', $this->fixture('linkedin_alert.txt'));

        self::assertCount(1, new LinkedInEmailParser()->parse($email));
    }

    public function testDropsAnOfferWhoseOnlyLabelIsGeneric(): void
    {
        $html = '<a href="https://www.hellowork.com/fr-fr/emplois/60000001.html">Voir l\'offre</a>'
            . '<a href="https://www.hellowork.com/fr-fr/emplois/60000002.html">Postuler</a>';

        self::assertSame([], new HelloWorkEmailParser()->parse($this->email('a@hellowork.com', $html)));
    }

    public function testAnEmailWithNoContentIsAParsingError(): void
    {
        $this->expectException(EmailParsingException::class);

        new LinkedInEmailParser()->parse($this->email('jobs-noreply@linkedin.com', null));
    }

    public function testAnEmailWithoutOfferLinksYieldsNothing(): void
    {
        self::assertSame([], new LinkedInEmailParser()->parse($this->email('jobs-noreply@linkedin.com', '<p>Votre compte a été mis à jour.</p>')));
    }

    public function testKeepsDistinctOffersThatShareAnOverlappingCard(): void
    {
        $html = '<div><a href="https://www.hellowork.com/fr-fr/emplois/1.html">Offre PHP A</a>'
            . '<a href="https://www.hellowork.com/fr-fr/emplois/2.html">Offre PHP B</a></div>';

        $jobs = new HelloWorkEmailParser()->parse($this->email('a@hellowork.com', $html));

        self::assertCount(2, $jobs);
    }

    public function testGenericParserDoesNothingUntilConfigured(): void
    {
        $email = $this->email('alerte@exemple-emploi.test', $this->fixture('generic_alert.html'));

        $unconfigured = new GenericEmailParser();

        self::assertFalse($unconfigured->supports($email));
        self::assertSame([], $unconfigured->parse($email), 'Sans domaine de lien configuré, aucun lien arbitraire n\'est retenu.');
        self::assertFalse(new GenericEmailParser([], ['jobs.exemple-emploi.test'])->supports($email), "Expéditeur non listé : l'email n'est pas pris en charge.");
    }

    public function testGenericParserKeepsOnlyConfiguredLinkDomains(): void
    {
        $parser = new GenericEmailParser(['exemple-emploi.test'], ['jobs.exemple-emploi.test']);
        $email = $this->email('alerte@exemple-emploi.test', $this->fixture('generic_alert.html'));

        self::assertTrue($parser->supports($email));
        $jobs = $parser->parse($email);

        self::assertCount(1, $jobs);
        self::assertSame('https://jobs.exemple-emploi.test/offres/12345?ref=a1', $jobs[0]->url, 'Le suivi est retiré, `ref` conservé.');
        self::assertSame('email-generic', $jobs[0]->source);
    }

    private function email(string $from, ?string $html, ?string $text = null): EmailMessage
    {
        return new EmailMessage('INBOX', '1', 100, '<id@test>', $from, 'Alerte', new \DateTimeImmutable(), $html, $text);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../Fixtures/email/' . $name);
    }
}
