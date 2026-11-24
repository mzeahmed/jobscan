<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\DTO\JobDto;
use Psr\Log\NullLogger;
use App\Provider\Ats\AtsBoard;
use PHPUnit\Framework\TestCase;
use App\DTO\PublicationDateSource;
use App\Provider\Ats\AshbyProvider;
use App\Provider\Ats\LeverProvider;
use App\Monitoring\FetchDiagnostics;
use App\DTO\PublicationDateConfidence;
use App\Provider\Ats\WorkableProvider;
use App\Provider\JobProviderInterface;
use App\Provider\Ats\RecruiteeProvider;
use App\Provider\Ats\GreenhouseProvider;
use App\Provider\Ats\SmartRecruitersProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Les API réelles ne sont jamais appelées : réponses simulées, fixtures anonymisées. Les champs de Greenhouse, Lever et Ashby
 * suivent la documentation officielle de chaque éditeur ; ceux de Recruitee, Workable et SmartRecruiters ont été relevés sur
 * des réponses réelles lors de l'écriture des providers (données remplacées par des valeurs fictives).
 */
final class AtsProvidersTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];
    /** @var list<int> */
    private array $sleeps = [];
    private FetchDiagnostics $diagnostics;

    protected function setUp(): void
    {
        $this->requests = [];
        $this->sleeps = [];
        $this->diagnostics = new FetchDiagnostics();
    }

    // --- Greenhouse -----------------------------------------------------------------------------

    public function testGreenhouseMapsOffersWithoutInventingMissingData(): void
    {
        $jobs = $this->greenhouse([$this->json('greenhouse_jobs.json')], ['exemple=Exemple SAS'])->fetch();

        self::assertCount(2, $jobs, 'Sans identifiant, sans titre ou avec une URL non http(s) : ignorées.');
        [$first, $second] = $jobs;

        self::assertSame('Développeur PHP Symfony', $first->title);
        self::assertSame('greenhouse', $first->source);
        self::assertSame('exemple:4001001', $first->externalId, 'Identifiant préfixé du board.');
        self::assertSame('https://careers.exemple.test/jobs/4001001', $first->url);
        self::assertSame('Exemple SAS', $first->company, "Nom saisi dans la configuration, jamais déduit de l'identifiant.");
        self::assertSame('Marseille, France', $first->location);
        self::assertStringContainsString('développeur PHP', $first->description, 'Le HTML échappé en entités est décodé.');
        self::assertStringContainsString('Symfony Docker', $first->description, 'Les blocs ne collent pas les mots.');
        self::assertStringNotContainsString('<', $first->description);
        self::assertStringContainsString('Département : Ingénierie.', $first->description);
        self::assertSame('Remote - France', $second->location);
    }

    public function testGreenhouseNeverTreatsUpdatedAtAsAPublicationDate(): void
    {
        foreach ($this->greenhouse([$this->json('greenhouse_jobs.json')])->fetch() as $job) {
            self::assertNull($job->publishedAt);
            self::assertSame(PublicationDateSource::Unknown, $job->publicationDateSource);
            self::assertSame(PublicationDateConfidence::Unknown, $job->publicationDateConfidence);
        }
    }

    public function testGreenhouseWithoutACompanyNameLeavesItUnknown(): void
    {
        $jobs = $this->greenhouse([$this->json('greenhouse_jobs.json')], ['exemple'])->fetch();

        self::assertNull($jobs[0]->company);
    }

    public function testGreenhouseCallsTheDocumentedEndpoint(): void
    {
        $this->greenhouse([$this->json('greenhouse_jobs.json')], ['exemple'])->fetch();

        self::assertSame('GET', $this->requests[0]['method']);
        self::assertStringStartsWith('https://boards-api.greenhouse.io/v1/boards/exemple/jobs?', $this->requests[0]['url']);
        self::assertStringContainsString('content=true', $this->requests[0]['url']);
    }

    // --- Lever ----------------------------------------------------------------------------------

    public function testLeverMapsOffersAndAddsStructuredInformationToTheDescription(): void
    {
        $jobs = $this->lever([$this->json('lever_page1.json'), new MockResponse('[]')], ['exemple=Exemple SAS'])->fetch();

        self::assertCount(2, $jobs, 'Une offre sans URL publique est ignorée.');
        [$first, $second] = $jobs;

        self::assertSame('Développeur PHP Symfony H/F', $first->title);
        self::assertSame('11111111-aaaa-bbbb-cccc-000000000001', $first->externalId);
        self::assertSame('Marseille', $first->location);
        self::assertSame('Exemple SAS', $first->company);
        self::assertStringContainsString("Rejoignez l'équipe plateforme.", $first->description);
        self::assertStringContainsString('Votre profil : PHP 8 Symfony', $first->description);
        self::assertStringContainsString('Contrat : CDI.', $first->description);
        self::assertStringContainsString('Mode de travail : hybrid.', $first->description);
        self::assertStringContainsString('Salaire : de 45000 à 55000 EUR (per-year-salary).', $first->description);

        self::assertSame('Remote - EMEA', $second->location, 'Repli sur allLocations.');
        self::assertStringNotContainsString('Mode de travail', $second->description, '« unspecified » n\'est pas une information.');
        self::assertStringContainsString('Salaire : Entre 50k et 60k selon expérience', $second->description);
    }

    public function testLeverNeverUsesAnUndocumentedPublicationDate(): void
    {
        foreach ($this->lever([$this->json('lever_page1.json'), new MockResponse('[]')])->fetch() as $job) {
            self::assertNull($job->publishedAt);
            self::assertSame(PublicationDateConfidence::Unknown, $job->publicationDateConfidence);
        }
    }

    public function testLeverPaginatesUntilAnEmptyPage(): void
    {
        $jobs = $this->lever([$this->json('lever_page1.json'), $this->json('lever_page2.json'), new MockResponse('[]')], pageSize: 3)->fetch();

        self::assertCount(3, $jobs);
        $skips = [];
        foreach ($this->requests as $request) {
            parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
            $skips[] = [$query['mode'], $query['skip'], $query['limit']];
        }
        self::assertSame([['json', '0', '3'], ['json', '3', '3'], ['json', '6', '3']], $skips);
    }

    public function testLeverStopsWhenTheApiIgnoresSkipAndRepeatsTheSameOffers(): void
    {
        $jobs = $this->lever([$this->json('lever_page1.json'), $this->json('lever_page1.json'), $this->json('lever_page1.json')])->fetch();

        self::assertCount(2, $jobs);
        self::assertCount(2, $this->requests, 'Une page sans nouvelle offre met fin à la pagination.');
    }

    public function testLeverStopsAtTheConfiguredMaximumOfPages(): void
    {
        $page = static fn (int $n): MockResponse => new MockResponse(json_encode([[
            'id' => 'id-' . $n, 'text' => 'Offre ' . $n, 'hostedUrl' => 'https://jobs.lever.co/exemple/' . $n,
        ]], JSON_THROW_ON_ERROR));

        $jobs = $this->lever([$page(1), $page(2), $page(3), $page(4)], maxPages: 2)->fetch();

        self::assertCount(2, $jobs);
        self::assertCount(2, $this->requests);
    }

    public function testLeverUsesTheEuropeanRegionWhenConfigured(): void
    {
        $this->lever([new MockResponse('[]')], ['exemple'], region: 'eu')->fetch();

        self::assertStringStartsWith('https://api.eu.lever.co/v0/postings/exemple?', $this->requests[0]['url']);
    }

    public function testLeverKeepsTheOffersCollectedBeforeAFailingPage(): void
    {
        $jobs = $this->lever([$this->json('lever_page1.json'), new MockResponse('', ['http_code' => 400])], pageSize: 3)->fetch();

        self::assertCount(2, $jobs);
        self::assertCount(1, $this->diagnostics->drain('lever')['errors']);
    }

    // --- Ashby ----------------------------------------------------------------------------------

    public function testAshbyMapsListedOffersWithAMediumConfidenceDate(): void
    {
        $jobs = $this->ashby([$this->json('ashby_job_board.json')], ['Exemple=Exemple SAS'])->fetch();

        self::assertCount(2, $jobs, 'L\'offre non listée est ignorée.');
        [$first, $second] = $jobs;

        self::assertSame('Développeur PHP Symfony', $first->title);
        self::assertSame('https://jobs.ashbyhq.com/exemple/aaaaaaaa-0000-0000-0000-000000000001', $first->url);
        self::assertNull($first->externalId, 'Aucun identifiant documenté : l\'identité est l\'URL.');
        self::assertSame('Exemple SAS', $first->company);
        self::assertSame('Marseille', $first->location);
        self::assertSame('2026-11-18', $first->publishedAt?->format('Y-m-d'));
        self::assertSame(PublicationDateSource::Api, $first->publicationDateSource);
        self::assertSame(PublicationDateConfidence::Medium, $first->publicationDateConfidence, '`publishedAt` est la dernière publication.');
        self::assertStringContainsString('Contrat : FullTime.', $first->description);
        self::assertStringContainsString('Mode de travail : Hybrid.', $first->description);
        self::assertStringContainsString('Autres lieux : Paris.', $first->description);
        self::assertStringContainsString('Rémunération : 45 000 € – 55 000 €', $first->description);

        self::assertNull($second->publishedAt, 'Une date absente reste inconnue.');
        self::assertSame(PublicationDateConfidence::Unknown, $second->publicationDateConfidence);
        self::assertStringContainsString('PHP Symfony', $second->description);
    }

    public function testAshbyCallsTheDocumentedEndpoint(): void
    {
        $this->ashby([$this->json('ashby_job_board.json')], ['Exemple'])->fetch();

        self::assertStringStartsWith('https://api.ashbyhq.com/posting-api/job-board/Exemple?', $this->requests[0]['url']);
        self::assertStringContainsString('includeCompensation=true', $this->requests[0]['url']);
    }

    // --- Recruitee ------------------------------------------------------------------------------

    public function testRecruiteeMapsPublishedOffersWithoutInventingMissingData(): void
    {
        $jobs = $this->recruitee([$this->json('recruitee_offers.json')])->fetch();

        self::assertCount(2, $jobs, 'Sans identifiant, sans URL http(s), archivée ou sans lien : ignorées.');
        [$first, $second] = $jobs;

        self::assertSame('Développeur PHP Symfony', $first->title);
        self::assertSame('recruitee', $first->source);
        self::assertSame('exemple:5001001', $first->externalId);
        self::assertSame('https://careers.exemple.test/o/developpeur-php-symfony', $first->url);
        self::assertSame('Exemple SAS', $first->company, 'Nom fourni par l\'API quand rien n\'est configuré.');
        self::assertSame('Marseille, Bouches-du-Rhône, France', $first->location);
        self::assertStringContainsString('Symfony Docker', $first->description);
        self::assertStringContainsString('5 ans d\'expérience en PHP', $first->description);
        self::assertStringContainsString('Contrat : fulltime permanent.', $first->description);
        self::assertStringContainsString('Mode de travail : hybride.', $first->description);
        self::assertStringContainsString('Rémunération : 45000–55000 EUR / year.', $first->description);
        self::assertStringNotContainsString('<', $first->description);

        self::assertStringContainsString('Mode de travail : télétravail possible.', $second->description);
        self::assertStringNotContainsString('Rémunération', $second->description, 'Un salaire vide n\'est pas affiché.');
        self::assertStringNotContainsString('Contrat', $second->description);
    }

    public function testRecruiteeDatesAreMediumConfidenceAndUnknownWhenAbsent(): void
    {
        [$first, $second] = $this->recruitee([$this->json('recruitee_offers.json')])->fetch();

        self::assertSame('2026-11-20', $first->publishedAt?->format('Y-m-d'));
        self::assertSame(PublicationDateSource::Api, $first->publicationDateSource);
        self::assertSame(PublicationDateConfidence::Medium, $first->publicationDateConfidence);
        self::assertNull($second->publishedAt);
        self::assertSame(PublicationDateConfidence::Unknown, $second->publicationDateConfidence);
    }

    public function testRecruiteeTheConfiguredCompanyNameWinsOverTheApiOne(): void
    {
        [$first] = $this->recruitee([$this->json('recruitee_offers.json')], ['exemple=Nom saisi'])->fetch();

        self::assertSame('Nom saisi', $first->company);
    }

    public function testRecruiteeBuildsTheHostFromTheBoardAndNeverEscapesTheDomain(): void
    {
        $this->recruitee([$this->json('recruitee_offers.json')], ['Exemple'])->fetch();
        self::assertSame('https://exemple.recruitee.com/api/offers/', $this->requests[0]['url']);

        $this->requests = [];
        $jobs = $this->recruitee([new MockResponse('{"offers":[]}')], ['a.b', 'x_y', 'ok-board'])->fetch();

        self::assertSame([], $jobs);
        self::assertSame(['https://ok-board.recruitee.com/api/offers/'], array_column($this->requests, 'url'), 'Seule une étiquette DNS valide devient un hôte.');
        $errors = $this->diagnostics->drain('recruitee')['errors'];
        self::assertCount(2, $errors);
        self::assertStringContainsString('identifiant invalide', $errors[0]);
    }

    // --- Workable -------------------------------------------------------------------------------

    public function testWorkableMapsOffersWithoutInventingMissingData(): void
    {
        $jobs = $this->workable([$this->json('workable_jobs.json')])->fetch();

        self::assertCount(2, $jobs, 'Sans shortcode ou avec une URL non http(s) : ignorées.');
        [$first, $second] = $jobs;

        self::assertSame('Développeur back-end PHP', $first->title);
        self::assertSame('workable', $first->source);
        self::assertSame('exemple:AB12CD34EF', $first->externalId);
        self::assertSame('https://apply.workable.com/j/AB12CD34EF', $first->url);
        self::assertSame('Exemple SAS (Workable)', $first->company, 'Nom du compte fourni par l\'API quand rien n\'est configuré.');
        self::assertSame('Marseille, Provence-Alpes-Côte d\'Azur, France', $first->location);
        self::assertStringContainsString('Symfony. PHP 8 PostgreSQL', $first->description);
        self::assertStringContainsString('Contrat : Full-time.', $first->description);
        self::assertStringContainsString('Département : Engineering.', $first->description);
        self::assertStringNotContainsString('télétravail', $first->description, '`telecommuting: false` ne signifie pas « sur site » : rien n\'est affirmé.');

        self::assertStringContainsString('Mode de travail : télétravail possible.', $second->description);
        self::assertNull($second->location, 'Aucun lieu : inconnu.');
        self::assertStringNotContainsString('Département', $second->description);
    }

    public function testWorkableDatesAreDayPrecisionMediumConfidenceAndUnknownWhenEmpty(): void
    {
        [$first, $second] = $this->workable([$this->json('workable_jobs.json')])->fetch();

        self::assertSame('2026-11-18', $first->publishedAt?->format('Y-m-d'));
        self::assertSame(PublicationDateConfidence::Medium, $first->publicationDateConfidence);
        self::assertNull($second->publishedAt);
        self::assertSame(PublicationDateSource::Unknown, $second->publicationDateSource);
    }

    public function testWorkableCallsTheObservedEndpointAndAnEmptyAccountGivesNothing(): void
    {
        $jobs = $this->workable([new MockResponse('{"name":"x","description":null,"jobs":[]}')], ['Exemple'])->fetch();

        self::assertSame([], $jobs);
        self::assertStringStartsWith('https://apply.workable.com/api/v1/widget/accounts/Exemple?', $this->requests[0]['url']);
        self::assertStringContainsString('details=true', $this->requests[0]['url']);
        self::assertSame([], $this->diagnostics->drain('workable')['errors']);
    }

    // --- SmartRecruiters --------------------------------------------------------------------------

    public function testSmartRecruitersFetchesTheDescriptionOfEachOfferFromItsDetail(): void
    {
        $jobs = $this->smartRecruiters([$this->json('smartrecruiters_postings.json'), $this->json('smartrecruiters_posting.json'), new MockResponse('{}')])->fetch();

        self::assertCount(2, $jobs, 'Sans nom ou avec un identifiant douteux : ignorées.');
        [$first, $second] = $jobs;

        self::assertSame('Développeur PHP Symfony', $first->title);
        self::assertSame('smartrecruiters', $first->source);
        self::assertSame('Exemple:744000100001', $first->externalId);
        self::assertSame('https://jobs.smartrecruiters.com/Exemple/744000100001-developpeur-php-symfony', $first->url, 'URL publique donnée par le détail.');
        self::assertSame('Exemple SAS', $first->company);
        self::assertSame('Marseille, PACA, France', $first->location);
        self::assertStringContainsString('API Symfony. Docker', $first->description);
        self::assertStringContainsString('5 ans de PHP', $first->description);
        self::assertStringContainsString('Contrat : Full-time.', $first->description);
        self::assertStringContainsString('Niveau d\'expérience : Mid-Senior Level.', $first->description);
        self::assertStringContainsString('Mode de travail : hybride.', $first->description);
        self::assertSame('2026-11-19', $first->publishedAt?->format('Y-m-d'));
        self::assertSame(PublicationDateConfidence::Medium, $first->publicationDateConfidence);

        self::assertStringContainsString('Mode de travail : télétravail possible.', $second->description);
    }

    public function testSmartRecruitersNeverFollowsTheRefUrlFromTheResponse(): void
    {
        $this->smartRecruiters([$this->json('smartrecruiters_postings.json'), $this->json('smartrecruiters_posting.json'), new MockResponse('{}')])->fetch();

        foreach ($this->requests as $request) {
            self::assertStringStartsWith('https://api.smartrecruiters.com/v1/companies/Exemple/postings', $request['url']);
            self::assertStringNotContainsString('evil.test', $request['url']);
        }
        self::assertSame('https://api.smartrecruiters.com/v1/companies/Exemple/postings/744000100001', $this->requests[1]['url']);
        self::assertSame('https://api.smartrecruiters.com/v1/companies/Exemple/postings/744000100002', $this->requests[2]['url']);
        self::assertCount(3, $this->requests, 'Un identifiant d\'offre douteux ne donne jamais de requête.');
    }

    public function testSmartRecruitersOnlyFetchesTheConfiguredNumberOfDetailsAndKeepsTheRest(): void
    {
        $jobs = $this->smartRecruiters([$this->json('smartrecruiters_postings.json'), $this->json('smartrecruiters_posting.json')], maxDetails: 1)->fetch();

        self::assertCount(2, $jobs);
        self::assertCount(2, $this->requests, 'La liste, puis un seul détail.');
        self::assertStringContainsString('API Symfony. Docker', $jobs[0]->description);
        self::assertStringNotContainsString('Symfony', $jobs[1]->description, 'Sans détail : uniquement les informations de la liste.');
        self::assertStringContainsString('Contrat : Contract.', $jobs[1]->description);
        self::assertSame('https://jobs.smartrecruiters.com/Exemple/744000100002', $jobs[1]->url, 'URL publique construite localement.');
    }

    public function testSmartRecruitersPaginatesUntilTheTotalIsReached(): void
    {
        $page = static fn (int $from, int $count): MockResponse => new MockResponse((string) json_encode([
            'offset' => $from, 'limit' => 2, 'totalFound' => 3,
            'content' => array_map(static fn (int $i): array => ['id' => 'id' . $i, 'name' => 'Offre ' . $i], range($from + 1, $from + $count)),
        ]), ['http_code' => 200]);

        $jobs = $this->smartRecruiters([$page(0, 2), $page(2, 1)], pageSize: 2, maxDetails: 0)->fetch();

        self::assertCount(3, $jobs);
        self::assertCount(2, $this->requests, 'Arrêt dès que le total est atteint.');
        self::assertStringContainsString('offset=2', $this->requests[1]['url']);
        self::assertStringContainsString('limit=2', $this->requests[1]['url']);
    }

    public function testSmartRecruitersStopsAtTheMaximumNumberOfPages(): void
    {
        $page = static fn (): MockResponse => new MockResponse((string) json_encode(['totalFound' => 999, 'content' => [['id' => 'a' . random_int(1, 9999), 'name' => 'Offre'], ['id' => 'b' . random_int(1, 9999), 'name' => 'Offre']]]), ['http_code' => 200]);

        $this->smartRecruiters([$page(), $page(), $page(), $page()], pageSize: 2, maxPages: 3, maxDetails: 0)->fetch();

        self::assertCount(3, $this->requests);
    }

    public function testSmartRecruitersKeepsOffersWhenADetailFailsAndStopsAfterRepeatedFailures(): void
    {
        $content = array_map(static fn (int $i): array => ['id' => 'id' . $i, 'name' => 'Offre ' . $i], range(1, 8));
        $failing = static fn (): MockResponse => new MockResponse('', ['http_code' => 404]);
        $responses = [new MockResponse((string) json_encode(['totalFound' => 8, 'content' => $content]), ['http_code' => 200]), $failing(), $failing(), $failing(), $failing(), $failing()];

        $jobs = $this->smartRecruiters($responses, maxDetails: 30)->fetch();

        self::assertCount(8, $jobs, 'Les offres restent importées sans description.');
        self::assertCount(4, $this->requests, 'La liste, puis trois échecs de détail consécutifs : on arrête de solliciter l\'API.');
    }

    public function testSmartRecruitersKeepsThePagesCollectedBeforeAFailingOne(): void
    {
        $first = new MockResponse((string) json_encode(['totalFound' => 4, 'content' => [['id' => 'a1', 'name' => 'Offre A'], ['id' => 'a2', 'name' => 'Offre B']]]), ['http_code' => 200]);

        $jobs = $this->smartRecruiters([$first, new MockResponse('', ['http_code' => 404])], pageSize: 2, maxDetails: 0)->fetch();

        self::assertCount(2, $jobs);
        self::assertCount(1, $this->diagnostics->drain('smartrecruiters')['errors']);
    }

    // --- Comportement commun --------------------------------------------------------------------

    /** @return iterable<string, array{string, string}> */
    public static function providers(): iterable
    {
        yield 'greenhouse' => ['greenhouse', 'greenhouse_jobs.json'];
        yield 'lever' => ['lever', 'lever_page1.json'];
        yield 'ashby' => ['ashby', 'ashby_job_board.json'];
        yield 'recruitee' => ['recruitee', 'recruitee_offers.json'];
        yield 'workable' => ['workable', 'workable_jobs.json'];
        yield 'smartrecruiters' => ['smartrecruiters', 'smartrecruiters_postings.json'];
    }

    #[DataProvider('providers')]
    public function testWithoutBoardsTheProviderIsDisabledAndNeverCallsTheNetwork(string $name, string $fixture): void
    {
        $provider = $this->make($name, [], boards: [null, '', '  ']);

        self::assertTrue($provider->isHealthy());
        self::assertSame([], $provider->fetch());
        self::assertSame([], $this->requests);
        self::assertTrue($this->diagnostics->drain($name)['disabled']);
    }

    #[DataProvider('providers')]
    public function testRejectsBoardIdentifiersThatCouldRedirectTheRequest(string $name, string $fixture): void
    {
        $provider = $this->make($name, [new MockResponse('[]')], boards: [
            'evil.test/../x', 'a b', '../../etc', 'x?y=1', 'https://evil.test', '-bad', '=OnlyName',
        ]);

        self::assertSame([], $provider->fetch());
        self::assertSame([], $this->requests, 'Aucun identifiant douteux n\'est jamais inséré dans une URL.');
    }

    #[DataProvider('providers')]
    public function testAFailingBoardDoesNotPreventTheOthers(string $name, string $fixture): void
    {
        $provider = $this->make($name, [new MockResponse('', ['http_code' => 404]), $this->json($fixture), new MockResponse('[]')], boards: ['inconnu', 'exemple']);

        $jobs = $provider->fetch();

        self::assertNotEmpty($jobs);
        $errors = $this->diagnostics->drain($name)['errors'];
        self::assertCount(1, $errors);
        self::assertStringContainsString('inconnu', $errors[0]);
        self::assertStringContainsString('404', $errors[0]);
    }

    #[DataProvider('providers')]
    public function testRetriesRateLimitingHonouringRetryAfter(string $name, string $fixture): void
    {
        $limited = new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 3']]);

        $jobs = $this->make($name, [$limited, $this->json($fixture), new MockResponse('[]')])->fetch();

        self::assertNotEmpty($jobs);
        self::assertContains(3000, $this->sleeps);
        self::assertSame([], $this->diagnostics->drain($name)['errors']);
    }

    #[DataProvider('providers')]
    public function testRetriesServerErrorsWithBackoffThenReportsTheFailure(string $name, string $fixture): void
    {
        $failing = static fn (): MockResponse => new MockResponse('', ['http_code' => 503]);

        $jobs = $this->make($name, [$failing(), $failing(), $failing()])->fetch();

        self::assertSame([], $jobs);
        self::assertContains(500, $this->sleeps);
        self::assertContains(1000, $this->sleeps);
        self::assertCount(1, $this->diagnostics->drain($name)['errors']);
    }

    #[DataProvider('providers')]
    public function testDoesNotRetryAClientError(string $name, string $fixture): void
    {
        $this->make($name, [new MockResponse('', ['http_code' => 404])])->fetch();

        self::assertCount(1, $this->requests);
    }

    #[DataProvider('providers')]
    public function testAnInvalidJsonResponseIsReportedWithoutRaising(string $name, string $fixture): void
    {
        $jobs = $this->make($name, [new MockResponse('<html>pas du JSON</html>')])->fetch();

        self::assertSame([], $jobs);
        self::assertCount(1, $this->diagnostics->drain($name)['errors']);
    }

    #[DataProvider('providers')]
    public function testThrottlesRequestsBetweenBoards(string $name, string $fixture): void
    {
        $this->make($name, [$this->json($fixture), new MockResponse('[]'), $this->json($fixture), new MockResponse('[]')], boards: ['a', 'b'], interval: 5000)->fetch();

        self::assertNotEmpty(array_filter($this->sleeps, static fn (int $ms): bool => $ms > 0 && $ms <= 5000));
    }

    #[DataProvider('providers')]
    public function testHealthSucceedsWhenAtLeastOneBoardAnswers(string $name, string $fixture): void
    {
        $responses = [new MockResponse('', ['http_code' => 404]), new MockResponse('{}')];

        self::assertTrue($this->make($name, $responses, boards: ['inconnu', 'exemple'])->isHealthy());
        self::assertFalse($this->make($name, [new MockResponse('', ['http_code' => 500])])->isHealthy());
    }

    #[DataProvider('providers')]
    public function testOfferUrlsAreDeduplicatedAcrossBoards(string $name, string $fixture): void
    {
        $jobs = $this->make($name, [$this->json($fixture), new MockResponse('[]'), $this->json($fixture), new MockResponse('[]')], boards: ['a', 'b'])->fetch();
        $urls = array_map(static fn (JobDto $job): string => $job->url, $jobs);

        self::assertSame($urls, array_values(array_unique($urls)));
    }

    public function testBoardParsingKeepsTheCompanyNameAndRejectsUnsafeTokens(): void
    {
        $board = AtsBoard::tryFromConfig(' exemple-sas = Exemple, SAS ');

        self::assertSame('exemple-sas', $board?->token);
        self::assertSame('Exemple, SAS', $board?->company);
        self::assertNull(AtsBoard::tryFromConfig('exemple/jobs'));
        self::assertNull(AtsBoard::tryFromConfig(''));
        self::assertNull(AtsBoard::tryFromConfig(null));
        self::assertNull(AtsBoard::tryFromConfig(str_repeat('a', 101)));
        self::assertNull(AtsBoard::tryFromConfig('exemple=')?->company);
    }

    // --- Outils ---------------------------------------------------------------------------------

    /**
     * @param list<MockResponse> $responses
     * @param list<string|null> $boards
     */
    private function make(string $name, array $responses, array $boards = ['exemple'], int $interval = 0): JobProviderInterface
    {
        return match ($name) {
            'greenhouse' => $this->greenhouse($responses, $boards, $interval),
            'lever' => $this->lever($responses, $boards, interval: $interval),
            'ashby' => $this->ashby($responses, $boards, $interval),
            'recruitee' => $this->recruitee($responses, $boards, $interval),
            'workable' => $this->workable($responses, $boards, $interval),
            'smartrecruiters' => $this->smartRecruiters($responses, $boards, $interval, maxDetails: 0),
        };
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string|null> $boards
     */
    private function greenhouse(array $responses, array $boards = ['exemple'], int $interval = 0): GreenhouseProvider
    {
        return new GreenhouseProvider($this->client($responses), new NullLogger(), $boards, $interval, $this->sleeper(), $this->diagnostics);
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string|null> $boards
     */
    private function lever(array $responses, array $boards = ['exemple'], string $region = 'global', int $pageSize = 100, int $maxPages = 10, int $interval = 0): LeverProvider
    {
        return new LeverProvider($this->client($responses), new NullLogger(), $boards, $interval, $this->sleeper(), $this->diagnostics, $region, $pageSize, $maxPages);
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string|null> $boards
     */
    private function ashby(array $responses, array $boards = ['exemple'], int $interval = 0): AshbyProvider
    {
        return new AshbyProvider($this->client($responses), new NullLogger(), $boards, $interval, $this->sleeper(), $this->diagnostics);
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string|null> $boards
     */
    private function recruitee(array $responses, array $boards = ['exemple'], int $interval = 0): RecruiteeProvider
    {
        return new RecruiteeProvider($this->client($responses), new NullLogger(), $boards, $interval, $this->sleeper(), $this->diagnostics);
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string|null> $boards
     */
    private function workable(array $responses, array $boards = ['exemple'], int $interval = 0): WorkableProvider
    {
        return new WorkableProvider($this->client($responses), new NullLogger(), $boards, $interval, $this->sleeper(), $this->diagnostics);
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string|null> $boards
     */
    private function smartRecruiters(array $responses, array $boards = ['Exemple'], int $interval = 0, int $pageSize = 100, int $maxPages = 10, int $maxDetails = 30): SmartRecruitersProvider
    {
        return new SmartRecruitersProvider($this->client($responses), new NullLogger(), $boards, $interval, $this->sleeper(), $this->diagnostics, $pageSize, $maxPages, $maxDetails);
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });
    }

    private function sleeper(): \Closure
    {
        return function (int $ms): void {
            $this->sleeps[] = $ms;
        };
    }

    private function json(string $fixture): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/../../Fixtures/ats/' . $fixture), ['http_code' => 200]);
    }
}
