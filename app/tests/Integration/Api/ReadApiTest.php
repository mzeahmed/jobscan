<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Tracking\JobUserState;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;
use App\Tests\Integration\Web\WebTestCaseBase;

/**
 * Les routes de lecture de l'API (offres, candidatures, analytics) : accès, filtres, pagination, forme des données
 * et traitement des contenus non fiables.
 */
final class ReadApiTest extends WebTestCaseBase
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['username' => 'admin', 'password' => self::PASSWORD]));
        $this->token = $this->json()['data']['access_token'];
        $this->client->getCookieJar()->clear();
    }

    /** @return array<mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = [], bool $authenticated = true): void
    {
        $this->client->request('GET', $path, $query, server: $authenticated ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token] : []);
    }

    private function job(string $title, string $source = 'rss', int $score = 50, string $description = 'Description', ?string $url = null): Job
    {
        $job = Job::fromDTO(new JobDto($title, $url ?? 'https://jobs.example/' . bin2hex(random_bytes(4)), $description, $source, null, company: 'Exemple SAS', location: 'Paris'));
        $job->setScore($score);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    private function manager(): ApplicationManager
    {
        return self::getContainer()->get(ApplicationManager::class);
    }

    // --- Accès ----------------------------------------------------------------------------------

    public function testEveryReadRouteRequiresAnAccessToken(): void
    {
        $job = $this->job('Offre');
        $application = $this->manager()->start($job, new \DateTimeImmutable());
        $this->entityManager->flush();

        foreach (['/api/v1/jobs', '/api/v1/jobs/' . $job->getId(), '/api/v1/applications', '/api/v1/applications/' . $application->getId(), '/api/v1/analytics'] as $path) {
            $this->get($path, authenticated: false);

            self::assertResponseStatusCodeSame(401, $path);
            self::assertSame('unauthorized', $this->json()['code']);
        }
    }

    public function testReadRoutesAreNotCached(): void
    {
        $this->get('/api/v1/jobs');

        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    // --- Offres : liste -------------------------------------------------------------------------

    public function testTheJobListHasItemsPaginationAndSources(): void
    {
        $this->job('A', 'rss', 80);
        $this->job('B', 'lever', 40);

        $this->get('/api/v1/jobs');

        self::assertResponseStatusCodeSame(200);
        $data = $this->json()['data'];
        self::assertCount(2, $data['items']);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 2, 'total_pages' => 1], $data['pagination']);
        self::assertSame(['lever', 'rss'], $data['sources']);
        self::assertSame(['id', 'title', 'url', 'source', 'company', 'location', 'remote', 'freelance', 'contract_type', 'seniority', 'stack', 'score', 'excerpt', 'created_at', 'published_at', 'notified_at', 'user_state', 'application_id'], array_keys($data['items'][0]));
    }

    public function testPaginationSplitsStablyAndClampsTheLastPage(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->job("Offre $i");
        }

        $this->get('/api/v1/jobs', ['per_page' => '2', 'page' => '1']);
        $first = array_column($this->json()['data']['items'], 'id');
        $this->get('/api/v1/jobs', ['per_page' => '2', 'page' => '2']);
        $second = array_column($this->json()['data']['items'], 'id');
        $this->get('/api/v1/jobs', ['per_page' => '2', 'page' => '99']);
        $last = $this->json()['data'];

        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertSame([], array_intersect($first, $second), 'Aucune offre sur deux pages.');
        self::assertSame(3, $last['pagination']['page']);
        self::assertSame(3, $last['pagination']['total_pages']);
        self::assertCount(1, $last['items']);
    }

    public function testFiltersNarrowTheList(): void
    {
        $this->job('Senior PHP', 'rss', 90);
        $this->job('Junior JS', 'lever', 30);

        $this->get('/api/v1/jobs', ['source' => 'lever']);
        self::assertSame(['Junior JS'], array_column($this->json()['data']['items'], 'title'));

        $this->get('/api/v1/jobs', ['min_score' => '60']);
        self::assertSame(['Senior PHP'], array_column($this->json()['data']['items'], 'title'));

        $this->get('/api/v1/jobs', ['sort' => 'score']);
        self::assertSame(['Senior PHP', 'Junior JS'], array_column($this->json()['data']['items'], 'title'));

        $this->get('/api/v1/jobs', ['location' => 'Lyon']);
        self::assertSame(0, $this->json()['data']['pagination']['total']);
    }

    public function testDecidedJobsAreHiddenUnlessAskedFor(): void
    {
        $kept = $this->job('Gardée');
        $ignored = $this->job('Ignorée');
        $ignored->setUserState(JobUserState::Ignored, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->get('/api/v1/jobs');
        self::assertSame([$kept->getId()], array_column($this->json()['data']['items'], 'id'));

        $this->get('/api/v1/jobs', ['state' => 'ignored']);
        $items = $this->json()['data']['items'];
        self::assertSame([$ignored->getId()], array_column($items, 'id'));
        self::assertSame(['value' => 'ignored', 'label' => 'Ignorée'], $items[0]['user_state']);
    }

    public function testTheListLinksTheTrackedApplication(): void
    {
        $job = $this->job('Suivie');
        $application = $this->manager()->start($job, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->get('/api/v1/jobs');

        self::assertSame($application->getId(), $this->json()['data']['items'][0]['application_id']);
    }

    public function testInvalidFiltersAreRefusedNotIgnored(): void
    {
        foreach ([['remote' => 'peut-etre'], ['min_score' => '150'], ['min_score' => 'abc'], ['sort' => 'prix'], ['per_page' => '1000'], ['page' => '0'], ['state' => 'tout'], ['age' => '3']] as $query) {
            $this->get('/api/v1/jobs', $query);

            self::assertResponseStatusCodeSame(422, json_encode($query));
            $body = $this->json();
            self::assertFalse($body['success']);
            self::assertArrayHasKey(array_key_first($query), $body['data']['errors']);
        }
    }

    public function testAnArrayWhereAScalarIsExpectedIsABadRequestInTheEnvelope(): void
    {
        $this->get('/api/v1/jobs', ['source' => ['a', 'b']]);

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->json()['success']);
    }

    // --- Offres : contenu non fiable ------------------------------------------------------------

    public function testAdContentIsExposedAsPlainTextWithASafeUrl(): void
    {
        $trapped = $this->job('Piège', description: '<p>Bonjour <script>alert(1)</script><b>monde</b> &amp; Cie</p>', url: 'javascript:alert(1)');
        $fine = $this->job('Sûre', url: 'https://jobs.example/ok');

        $this->get('/api/v1/jobs/' . $trapped->getId());
        $job = $this->json()['data']['job'];
        self::assertNull($job['url'], 'Une URL non http(s) n\'est jamais publiée.');
        self::assertStringNotContainsString('<', $job['description']);
        self::assertStringNotContainsString('<', $job['excerpt']);
        self::assertStringContainsString('monde & Cie', $job['description']);

        $this->get('/api/v1/jobs/' . $fine->getId());
        self::assertSame('https://jobs.example/ok', $this->json()['data']['job']['url']);
    }

    public function testTheExcerptIsBounded(): void
    {
        $this->job('Longue', description: str_repeat('mot ', 500));

        $this->get('/api/v1/jobs');

        $excerpt = $this->json()['data']['items'][0]['excerpt'];
        self::assertLessThanOrEqual(241, mb_strlen((string) $excerpt));
        self::assertStringEndsWith('…', $excerpt);
    }

    // --- Offres : détail ------------------------------------------------------------------------

    public function testTheJobDetailAddsTheExplanations(): void
    {
        $job = $this->job('Détail', score: 77);

        $this->get('/api/v1/jobs/' . $job->getId());

        self::assertResponseStatusCodeSame(200);
        $data = $this->json()['data']['job'];
        self::assertSame(77, $data['score']);
        foreach (['description', 'budget', 'score_breakdown', 'requirements', 'publication', 'analysis', 'first_seen_at', 'last_seen_at', 'probable_duplicate_of_id'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
        self::assertSame(['source', 'confidence'], array_keys($data['publication']));
    }

    public function testAnUnknownOrMalformedJobIsA404InTheEnvelope(): void
    {
        foreach (['/api/v1/jobs/999999', '/api/v1/jobs/abc', '/api/v1/jobs/99999999999999999999999'] as $path) {
            $this->get($path);

            self::assertResponseStatusCodeSame(404, $path);
            self::assertFalse($this->json()['success']);
        }
    }

    // --- Candidatures ---------------------------------------------------------------------------

    public function testTheApplicationListCountsEveryStatusAndFilters(): void
    {
        $now = new \DateTimeImmutable();
        $this->manager()->start($this->job('Brouillon'), $now);
        $applied = $this->manager()->start($this->job('Postulée'), $now);
        $this->manager()->changeStatus($applied, ApplicationStatus::Applied, $now);
        $this->entityManager->flush();

        $this->get('/api/v1/applications');
        $all = $this->json()['data'];
        $this->get('/api/v1/applications', ['status' => 'applied']);
        $filtered = $this->json()['data'];

        self::assertCount(2, $all['items']);
        self::assertSame(2, $all['total']);
        self::assertCount(\count(ApplicationStatus::cases()), $all['counts'], 'Un compteur par statut, même à zéro.');
        self::assertSame([$applied->getId()], array_column($filtered['items'], 'id'));
        self::assertSame(2, $filtered['total'], 'Les compteurs ignorent le filtre.');
        self::assertSame(['id', 'status', 'job', 'channel', 'cv_version', 'search_profile_id', 'recruiter_name', 'applied_at', 'last_contact_at', 'follow_up_at', 'follow_up_due', 'status_changed_at', 'created_at'], array_keys($all['items'][0]));
    }

    public function testAnUnknownApplicationStatusFilterIsRefused(): void
    {
        $this->get('/api/v1/applications', ['status' => 'embauche']);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('status', $this->json()['data']['errors']);
    }

    public function testTheApplicationDetailHasHistoryAndAllowedTransitions(): void
    {
        $now = new \DateTimeImmutable();
        $application = $this->manager()->start($this->job('Candidature'), $now);
        $this->manager()->changeStatus($application, ApplicationStatus::Applied, $now, 'Envoyée');
        $application->setNotes('Mes notes');
        $this->entityManager->flush();

        $this->get('/api/v1/applications/' . $application->getId());

        self::assertResponseStatusCodeSame(200);
        $data = $this->json()['data']['application'];
        self::assertSame('applied', $data['status']['value']);
        self::assertSame('Mes notes', $data['notes']);
        self::assertSame(['draft', 'applied'], array_map(static fn (array $step): string => $step['to']['value'], $data['history']));
        self::assertNull($data['history'][0]['from']);
        self::assertContains('interview', array_column($data['allowed_transitions'], 'value'));
        self::assertNotContains('draft', array_column($data['allowed_transitions'], 'value'));
    }

    public function testTheApplicationDetailListsTheChoicesOfTheEditForms(): void
    {
        $application = $this->manager()->start($this->job('Choix'), new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->get('/api/v1/applications/' . $application->getId());

        $options = $this->json()['data']['options'];
        self::assertContains('email', array_column($options['channels'], 'value'));
        self::assertCount(6, $options['channels']);
        self::assertSame([], $options['cv_versions'], 'Sans profil candidat, aucun CV n\'est proposé (et rien n\'est inventé).');
    }

    public function testTheApplicationDetailListsTheCvVersionsOfTheCandidateProfile(): void
    {
        $directory = sys_get_temp_dir() . '/jobscan-api-profile-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/candidate.yaml', "skills: [PHP]\ncvs:\n  - {id: backend-php, label: CV Backend PHP}\n");

        try {
            $this->restartWithEnvironment('JOBSCAN_PROFILE_DIR', $directory);
            $this->client->request('POST', '/api/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['username' => 'admin', 'password' => self::PASSWORD]));
            $this->token = $this->json()['data']['access_token'];
            $application = $this->manager()->start($this->job('Avec CV'), new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->get('/api/v1/applications/' . $application->getId());

            self::assertSame([['id' => 'backend-php', 'label' => 'CV Backend PHP']], $this->json()['data']['options']['cv_versions']);
        } finally {
            @unlink($directory . '/candidate.yaml');
            @rmdir($directory);
        }
    }

    public function testAnUnknownApplicationIsA404(): void
    {
        $this->get('/api/v1/applications/999999');

        self::assertResponseStatusCodeSame(404);
    }

    // --- Analytics ------------------------------------------------------------------------------

    public function testAnalyticsHideRatesOnATooSmallSample(): void
    {
        $now = new \DateTimeImmutable();
        $application = $this->manager()->start($this->job('Une'), $now);
        $this->manager()->changeStatus($application, ApplicationStatus::Applied, $now);
        $this->entityManager->flush();

        $this->get('/api/v1/analytics');

        self::assertResponseStatusCodeSame(200);
        $data = $this->json()['data'];
        self::assertSame('90d', $data['window']);
        self::assertSame(5, $data['min_sample']);
        self::assertSame(1, $data['funnel']['sent']);
        self::assertNull($data['funnel']['response_rate'], 'Un échantillon d\'une candidature ne donne pas de pourcentage.');
        self::assertNull($data['funnel']['median_days_to_response']);
        foreach (['source', 'cv', 'profile', 'channel'] as $dimension) {
            self::assertArrayHasKey($dimension, $data['breakdowns']);
        }
    }

    public function testAnalyticsAcceptTheKnownWindowsOnly(): void
    {
        foreach (['30d', '90d', '365d', 'all'] as $window) {
            $this->get('/api/v1/analytics', ['since' => $window]);
            self::assertResponseStatusCodeSame(200, $window);
            self::assertSame($window, $this->json()['data']['window']);
        }
        $this->get('/api/v1/analytics', ['since' => 'all']);
        self::assertNull($this->json()['data']['since']);

        foreach (['7d', 'hier', '', '99999d'] as $window) {
            $this->get('/api/v1/analytics', ['since' => $window]);
            self::assertResponseStatusCodeSame(422, $window);
        }
    }

    public function testAnalyticsOfAHealthyFunnelGiveRatesOnceTheSampleIsLargeEnough(): void
    {
        $now = new \DateTimeImmutable();
        for ($i = 1; $i <= 5; ++$i) {
            $application = $this->manager()->start($this->job("Offre $i"), $now->modify('-10 days'));
            $this->manager()->changeStatus($application, ApplicationStatus::Applied, $now->modify('-9 days'));
            if ($i <= 2) {
                $this->manager()->changeStatus($application, ApplicationStatus::Interview, $now->modify('-5 days'));
            }
        }
        $this->entityManager->flush();

        $this->get('/api/v1/analytics');

        $funnel = $this->json()['data']['funnel'];
        self::assertSame(5, $funnel['sent']);
        self::assertSame(2, $funnel['interviews']);
        self::assertEqualsWithDelta(0.4, $funnel['interview_rate'], 0.0001);
        self::assertEqualsWithDelta(4.0, $funnel['median_days_to_response'], 0.5);
    }
}
