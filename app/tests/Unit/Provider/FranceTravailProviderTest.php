<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use Psr\Log\AbstractLogger;
use PHPUnit\Framework\TestCase;
use App\DTO\PublicationDateSource;
use App\Monitoring\FetchDiagnostics;
use App\DTO\PublicationDateConfidence;
use App\Provider\FranceTravailProvider;
use App\Repository\ProviderRunRepository;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * L'API réelle n'est pas appelée : toutes les réponses viennent de mocks et de la fixture
 * anonymisée `Fixtures/france_travail/search_page.json`. Les formats simulés sont à valider
 * contre la documentation officielle (voir FranceTravailProvider).
 */
final class FranceTravailProviderTest extends TestCase
{
    private const string SECRET = 'super-secret-value';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];
    /** @var list<int> */
    private array $sleeps = [];
    private FetchDiagnostics $diagnostics;
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->requests = [];
        $this->sleeps = [];
        $this->diagnostics = new FetchDiagnostics();
        $this->logger = new CollectingLogger();
    }

    public function testDisabledProviderDoesNothingAndReportsItself(): void
    {
        $provider = $this->provider([], enabled: false);

        self::assertTrue($provider->isHealthy());
        self::assertSame([], $provider->fetch());
        self::assertSame([], $this->requests, 'Aucun appel réseau tant que le provider est désactivé.');
        self::assertTrue($this->diagnostics->drain('francetravail')['disabled']);
    }

    public function testMissingCredentialsAreReportedAsAnError(): void
    {
        $provider = $this->provider([], clientId: '', clientSecret: null);

        self::assertFalse($provider->isHealthy());
        self::assertSame([], $provider->fetch());
        self::assertSame([], $this->requests);
        $report = $this->diagnostics->drain('francetravail');
        self::assertFalse($report['disabled']);
        self::assertCount(1, $report['errors']);
    }

    public function testAuthenticatesThenMapsOffersWithoutInventingMissingData(): void
    {
        $provider = $this->provider([$this->token(), $this->page(), ]);

        $jobs = $provider->fetch();

        self::assertCount(2, $jobs, 'Les offres sans identifiant ou sans intitulé sont ignorées.');

        [$first, $second] = $jobs;
        self::assertSame('Développeur PHP Symfony H/F', $first->title);
        self::assertSame('000AAAA', $first->externalId);
        self::assertSame('francetravail', $first->source);
        self::assertSame('Exemple SAS', $first->company);
        self::assertSame('13 - MARSEILLE 01', $first->location);
        self::assertSame('https://candidat.exemple.test/offres/recherche/detail/000AAAA', $first->url);
        self::assertSame('2026-11-23', $first->publishedAt?->format('Y-m-d'), 'dateCreation, pas dateActualisation.');
        self::assertSame(PublicationDateSource::Api, $first->publicationDateSource);
        self::assertSame(PublicationDateConfidence::High, $first->publicationDateConfidence);
        self::assertStringContainsString('développeur PHP confirmé', $first->description);
        self::assertStringNotContainsString('<b>', $first->description);
        self::assertStringContainsString('Contrat : Contrat à durée indéterminée.', $first->description);
        self::assertStringContainsString('Salaire : Annuel de 40000 Euros', $first->description);

        self::assertNull($second->company, 'Une entreprise absente reste inconnue.');
        self::assertStringNotContainsString('Salaire', $second->description);
        self::assertSame('https://candidat.francetravail.fr/offres/recherche/detail/000BBBB', $second->url, 'URL de repli quand urlOrigine est absente.');
    }

    public function testSendsTheClientCredentialsGrantAndBearerToken(): void
    {
        $this->provider([$this->token('tok-123'), $this->page()])->fetch();

        [$tokenRequest, $searchRequest] = $this->requests;
        self::assertSame('POST', $tokenRequest['method']);
        self::assertStringContainsString('realm=/partenaire', $tokenRequest['url']);
        parse_str((string) $tokenRequest['options']['body'], $form);
        self::assertSame('client_credentials', $form['grant_type']);
        self::assertSame('my-client', $form['client_id']);
        self::assertSame(self::SECRET, $form['client_secret']);
        self::assertSame('api_offresdemploiv2 o2dsoffre', $form['scope']);

        self::assertSame('GET', $searchRequest['method']);
        self::assertStringContainsString('/offres/search', $searchRequest['url']);
        self::assertContains('Authorization: Bearer tok-123', $searchRequest['options']['headers']);
    }

    public function testSearchesEachKeywordAndDepartmentWithTheIncrementalWindow(): void
    {
        $provider = $this->provider(
            [$this->token(), $this->empty(), $this->empty(), $this->empty(), $this->empty()],
            keywords: ['php', 'symfony'],
            departments: ['13', null, '75'],
        );

        $provider->fetch();

        $searches = array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === 'GET'));
        self::assertCount(4, $searches, 'Mots-clés × départements, valeurs vides ignorées.');
        parse_str((string) parse_url($searches[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('php', $query['motsCles']);
        self::assertSame('13', $query['departement']);
        self::assertSame('0-149', $query['range']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $query['minCreationDate']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $query['maxCreationDate']);
    }

    public function testCollectsIncrementallyFromTheLastSuccessfulRun(): void
    {
        $lastSuccess = new \DateTimeImmutable('-3 hours');
        $runs = $this->createStub(ProviderRunRepository::class);
        $runs->method('findLastSuccessByProvider')->willReturn(['francetravail' => $lastSuccess]);

        $this->provider([$this->token(), $this->empty()], providerRuns: $runs)->fetch();

        parse_str((string) parse_url($this->requests[1]['url'], PHP_URL_QUERY), $query);
        $since = new \DateTimeImmutable($query['minCreationDate']);
        $expected = $lastSuccess->modify('-1 hour');
        self::assertLessThanOrEqual(1, abs($since->getTimestamp() - $expected->getTimestamp()), 'Dernier succès moins 1 h de recouvrement.');
    }

    public function testFirstRunFallsBackToTheLookbackWindow(): void
    {
        $this->provider([$this->token(), $this->empty()], lookbackDays: 2)->fetch();

        parse_str((string) parse_url($this->requests[1]['url'], PHP_URL_QUERY), $query);
        $since = new \DateTimeImmutable($query['minCreationDate']);
        self::assertEqualsWithDelta(time() - 2 * 86400, $since->getTimestamp(), 5);
    }

    public function testFollowsPartialContentPaginationUntilTheTotalIsReached(): void
    {
        $fullPage = static fn (int $from): MockResponse => new MockResponse(
            json_encode(['resultats' => array_map(
                static fn (int $i): array => ['id' => 'ID' . $i, 'intitule' => 'Offre PHP ' . $i, 'description' => 'PHP'],
                range($from, $from + 1),
            )], JSON_THROW_ON_ERROR),
            ['http_code' => 206, 'response_headers' => ['Content-Range: offres ' . $from . '-' . ($from + 1) . '/5']],
        );
        $last = new MockResponse(
            json_encode(['resultats' => [['id' => 'ID4', 'intitule' => 'Offre PHP 4', 'description' => 'PHP']]], JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['Content-Range: offres 4-4/5']],
        );

        $jobs = $this->provider([$this->token(), $fullPage(0), $fullPage(2), $last], pageSize: 2, maxPages: 10)->fetch();

        self::assertCount(5, $jobs);
        $ranges = [];
        foreach (array_slice($this->requests, 1) as $request) {
            parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
            $ranges[] = $query['range'];
        }

        self::assertSame(['0-1', '2-3', '4-5'], $ranges);
    }

    public function testStopsAtTheConfiguredMaximumNumberOfPages(): void
    {
        $page = static fn (int $from): MockResponse => new MockResponse(
            json_encode(['resultats' => [
                ['id' => 'A' . $from, 'intitule' => 'PHP', 'description' => 'x'],
                ['id' => 'B' . $from, 'intitule' => 'PHP', 'description' => 'x'],
            ]], JSON_THROW_ON_ERROR),
            ['http_code' => 206, 'response_headers' => ['Content-Range: offres 0-1/1000']],
        );

        $jobs = $this->provider([$this->token(), $page(0), $page(2), $page(4)], pageSize: 2, maxPages: 2)->fetch();

        self::assertCount(4, $jobs);
        self::assertCount(3, $this->requests, 'Un jeton et deux pages, pas davantage.');
    }

    public function testNoContentMeansNoOffers(): void
    {
        $jobs = $this->provider([$this->token(), new MockResponse('', ['http_code' => 204])])->fetch();

        self::assertSame([], $jobs);
        self::assertSame([], $this->diagnostics->drain('francetravail')['errors']);
    }

    public function testRetriesAfterRateLimitingHonouringRetryAfter(): void
    {
        $limited = new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 2']]);

        $jobs = $this->provider([$this->token(), $limited, $this->page()])->fetch();

        self::assertCount(2, $jobs);
        self::assertContains(2000, $this->sleeps);
        self::assertSame([], $this->diagnostics->drain('francetravail')['errors']);
    }

    public function testRetriesServerErrorsWithBackoffThenGivesUpWithADiagnostic(): void
    {
        $failing = static fn (): MockResponse => new MockResponse('', ['http_code' => 503]);

        $jobs = $this->provider([$this->token(), $failing(), $failing(), $failing()])->fetch();

        self::assertSame([], $jobs);
        self::assertContains(500, $this->sleeps);
        self::assertContains(1000, $this->sleeps);
        self::assertCount(1, $this->diagnostics->drain('francetravail')['errors']);
    }

    public function testRefreshesAnExpiredTokenOnceAfterAnUnauthorizedResponse(): void
    {
        $unauthorized = new MockResponse('', ['http_code' => 401]);

        $jobs = $this->provider([$this->token('old'), $unauthorized, $this->token('new'), $this->page()])->fetch();

        self::assertCount(2, $jobs);
        self::assertContains('Authorization: Bearer new', $this->requests[3]['options']['headers']);
    }

    public function testDoesNotRetryAClientError(): void
    {
        $jobs = $this->provider([$this->token(), new MockResponse('', ['http_code' => 400])])->fetch();

        self::assertSame([], $jobs);
        self::assertCount(2, $this->requests);
        self::assertCount(1, $this->diagnostics->drain('francetravail')['errors']);
    }

    public function testAnAuthenticationFailureIsReportedWithoutLeakingSecrets(): void
    {
        $provider = $this->provider([new MockResponse('{"error":"invalid_client"}', ['http_code' => 401])]);

        self::assertSame([], $provider->fetch());

        $errors = $this->diagnostics->drain('francetravail')['errors'];
        self::assertCount(1, $errors);
        $everything = implode(' ', $errors) . ' ' . implode(' ', $this->logger->messages);
        self::assertStringNotContainsString(self::SECRET, $everything);
    }

    public function testTheTokenIsCachedAcrossFetches(): void
    {
        $provider = $this->provider([$this->token(), $this->empty(), $this->empty()]);

        $provider->fetch();
        $provider->fetch();

        $tokenRequests = array_filter($this->requests, static fn (array $r): bool => $r['method'] === 'POST');
        self::assertCount(1, $tokenRequests);
    }

    public function testThrottlesRequestsBelowTheQuota(): void
    {
        $this->provider(
            [$this->token(), $this->empty(), $this->empty()],
            keywords: ['php', 'symfony'],
            minRequestIntervalMs: 400,
        )->fetch();

        self::assertNotEmpty(array_filter($this->sleeps, static fn (int $ms): bool => $ms > 0 && $ms <= 400));
    }

    public function testHealthCheckSucceedsWhenAnAccessTokenIsGranted(): void
    {
        self::assertTrue($this->provider([$this->token()])->isHealthy());
        self::assertFalse($this->provider([new MockResponse('', ['http_code' => 500])])->isHealthy());
    }

    public function testIgnoresOffersWhoseUrlIsNotHttp(): void
    {
        $page = new MockResponse(json_encode(['resultats' => [[
            'id' => 'X1',
            'intitule' => 'PHP',
            'description' => 'x',
            'origineOffre' => ['urlOrigine' => 'javascript:alert(1)'],
        ]]], JSON_THROW_ON_ERROR), ['http_code' => 200]);

        self::assertSame([], $this->provider([$this->token(), $page])->fetch());
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string> $keywords
     * @param list<string|null> $departments
     */
    private function provider(
        array $responses,
        bool $enabled = true,
        ?string $clientId = 'my-client',
        ?string $clientSecret = self::SECRET,
        array $keywords = [],
        array $departments = [],
        int $maxPages = 3,
        int $pageSize = 150,
        int $lookbackDays = 7,
        int $minRequestIntervalMs = 0,
        ?ProviderRunRepository $providerRuns = null,
    ): FranceTravailProvider {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new FranceTravailProvider(
            $client,
            $this->logger,
            new ArrayAdapter(),
            $enabled,
            $clientId,
            $clientSecret,
            keywords: $keywords,
            departments: $departments,
            maxPages: $maxPages,
            pageSize: $pageSize,
            lookbackDays: $lookbackDays,
            minRequestIntervalMs: $minRequestIntervalMs,
            sleeper: function (int $ms): void {
                $this->sleeps[] = $ms;
            },
            diagnostics: $this->diagnostics,
            providerRuns: $providerRuns,
        );
    }

    private function token(string $value = 'token-abc'): MockResponse
    {
        return new MockResponse(json_encode(['access_token' => $value, 'expires_in' => 1499, 'token_type' => 'Bearer'], JSON_THROW_ON_ERROR));
    }

    private function page(): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__ . '/../../Fixtures/france_travail/search_page.json'), ['http_code' => 200]);
    }

    private function empty(): MockResponse
    {
        return new MockResponse('', ['http_code' => 204]);
    }
}

final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log($level, string | \Stringable $message, array $context = []): void
    {
        $this->messages[] = $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
}
