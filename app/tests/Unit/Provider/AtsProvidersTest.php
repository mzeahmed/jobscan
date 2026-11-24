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
use App\Provider\JobProviderInterface;
use App\Provider\Ats\GreenhouseProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Les API réelles ne sont jamais appelées : réponses simulées, fixtures anonymisées dont les champs
 * suivent la documentation officielle de chaque éditeur (lue lors de l'écriture des providers).
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

    // --- Comportement commun --------------------------------------------------------------------

    /** @return iterable<string, array{string, string}> */
    public static function providers(): iterable
    {
        yield 'greenhouse' => ['greenhouse', 'greenhouse_jobs.json'];
        yield 'lever' => ['lever', 'lever_page1.json'];
        yield 'ashby' => ['ashby', 'ashby_job_board.json'];
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
