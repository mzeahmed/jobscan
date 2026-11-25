<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\DTO\JobDto;
use App\Entity\Job;
use App\Tracking\JobUserState;
use App\Repository\JobRepository;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;
use App\Repository\ApplicationRepository;
use App\Tests\Integration\WebTestCaseBase;

/**
 * Les routes d'écriture de l'API : suivre une offre, décider, changer le statut, éditer les informations.
 * Chaque écriture exige un jeton d'accès et un corps JSON, valide strictement ses saisies et ne touche à rien d'autre.
 */
final class WriteApiTest extends WebTestCaseBase
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

    private function send(string $method, string $path, mixed $body = null, bool $authenticated = true, string $contentType = 'application/json'): void
    {
        $server = ['CONTENT_TYPE' => $contentType] + ($authenticated ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token] : []);
        $this->client->request($method, $path, server: $server, content: \is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function job(string $title = 'Offre'): Job
    {
        $job = Job::fromDTO(new JobDto($title, 'https://jobs.example/' . bin2hex(random_bytes(4)), 'Description', 'rss', null, company: 'Exemple SAS'));
        $job->setScore(60);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    private function application(ApplicationStatus $status = ApplicationStatus::Draft): int
    {
        $manager = self::getContainer()->get(ApplicationManager::class);
        $application = $manager->start($this->job(), new \DateTimeImmutable('-3 days'));
        if ($status !== ApplicationStatus::Draft) {
            $manager->changeStatus($application, ApplicationStatus::Applied, new \DateTimeImmutable('-2 days'));
        }
        $this->entityManager->flush();

        return (int) $application->getId();
    }

    private function reload(string $class, int $id): object
    {
        $this->entityManager->clear();

        return $this->entityManager->find($class, $id) ?? self::fail('Introuvable.');
    }

    // --- Accès et corps -------------------------------------------------------------------------

    public function testEveryWriteRouteRequiresAnAccessToken(): void
    {
        $job = $this->job();
        $application = $this->application();

        foreach ([
            ['POST', "/api/v1/jobs/{$job->getId()}/application", null],
            ['PUT', "/api/v1/jobs/{$job->getId()}/decision", ['state' => 'saved']],
            ['POST', "/api/v1/applications/$application/status", ['status' => 'applied']],
            ['PATCH', "/api/v1/applications/$application", ['notes' => 'x']],
        ] as [$method, $path, $body]) {
            $this->send($method, $path, $body, authenticated: false);

            self::assertResponseStatusCodeSame(401, "$method $path");
        }

        self::assertSame(0, self::getContainer()->get(ApplicationRepository::class)->count([]) - 1, 'Aucune écriture sans jeton.');
    }

    public function testWritesRefuseANonJsonOrMalformedBody(): void
    {
        $application = $this->application();

        $this->send('PATCH', "/api/v1/applications/$application", 'notes=x', contentType: 'application/x-www-form-urlencoded');
        self::assertResponseStatusCodeSame(415);

        foreach (['pas du json', '[1,2]', '"texte"', '12'] as $body) {
            $this->send('PATCH', "/api/v1/applications/$application", $body);
            self::assertResponseStatusCodeSame(400, $body);
            self::assertFalse($this->json()['success']);
        }
    }

    public function testWritesToAnUnknownTargetAre404(): void
    {
        foreach ([['POST', '/api/v1/jobs/999999/application', null], ['PUT', '/api/v1/jobs/999999/decision', ['state' => 'saved']], ['POST', '/api/v1/applications/999999/status', ['status' => 'applied']], ['PATCH', '/api/v1/applications/999999', ['notes' => 'x']]] as [$method, $path, $body]) {
            $this->send($method, $path, $body);

            self::assertResponseStatusCodeSame(404, "$method $path");
        }
    }

    public function testTheWrongMethodIsRefusedInTheEnvelope(): void
    {
        $job = $this->job();

        $this->send('GET', "/api/v1/jobs/{$job->getId()}/application");
        self::assertResponseStatusCodeSame(405);

        $this->send('DELETE', "/api/v1/jobs/{$job->getId()}");
        self::assertResponseStatusCodeSame(405);
        self::assertFalse($this->json()['success']);
    }

    // --- Suivre une offre -----------------------------------------------------------------------

    public function testTrackingAJobOpensADraftAndSendsNothing(): void
    {
        $job = $this->job();

        $this->send('POST', "/api/v1/jobs/{$job->getId()}/application");

        self::assertResponseStatusCodeSame(201);
        $data = $this->json()['data'];
        self::assertTrue($data['created']);
        self::assertSame('draft', $data['application']['status']['value']);
        self::assertCount(1, $data['application']['history']);
        self::assertSame($job->getId(), $data['application']['job']['id']);
        self::assertStringContainsString("rien n'a été envoyé", $data['message']);
    }

    public function testTrackingIsIdempotent(): void
    {
        $job = $this->job();
        $this->send('POST', "/api/v1/jobs/{$job->getId()}/application");
        $first = $this->json()['data']['application']['id'];

        $this->send('POST', "/api/v1/jobs/{$job->getId()}/application");

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->json()['data']['created']);
        self::assertSame($first, $this->json()['data']['application']['id']);
        self::assertSame(1, self::getContainer()->get(ApplicationRepository::class)->count([]));
    }

    // --- Décider --------------------------------------------------------------------------------

    public function testDecidingSetsChangesAndClearsTheState(): void
    {
        $job = $this->job();
        $id = (int) $job->getId();

        $this->send('PUT', "/api/v1/jobs/$id/decision", ['state' => 'saved']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['value' => 'saved', 'label' => 'Sauvegardée'], $this->json()['data']['job']['user_state']);
        self::assertSame(JobUserState::Saved, $this->reload(Job::class, $id)->getUserState());

        $this->send('PUT', "/api/v1/jobs/$id/decision", ['state' => 'ignored']);
        self::assertSame(JobUserState::Ignored, $this->reload(Job::class, $id)->getUserState());

        $this->send('PUT', "/api/v1/jobs/$id/decision", ['state' => null]);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->json()['data']['job']['user_state']);
        self::assertNull($this->reload(Job::class, $id)->getUserState());
    }

    public function testADecisionRefusesUnknownOrMissingValues(): void
    {
        $job = $this->job();

        foreach ([['state' => 'perdue'], ['state' => 3], ['state' => 'none'], []] as $body) {
            $this->send('PUT', "/api/v1/jobs/{$job->getId()}/decision", $body);

            self::assertResponseStatusCodeSame(422, json_encode($body));
            self::assertArrayHasKey('state', $this->json()['data']['errors']);
        }
        self::assertNull($this->reload(Job::class, (int) $job->getId())->getUserState());
    }

    // --- Changer le statut ----------------------------------------------------------------------

    public function testAStatusChangeIsRecordedInTheHistory(): void
    {
        $id = $this->application();

        $this->send('POST', "/api/v1/applications/$id/status", ['status' => 'applied', 'at' => new \DateTimeImmutable('-1 day')->format('Y-m-d'), 'note' => 'Envoyée par mail']);

        self::assertResponseStatusCodeSame(200);
        $data = $this->json()['data']['application'];
        self::assertSame('applied', $data['status']['value']);
        self::assertSame(['draft', 'applied'], array_map(static fn (array $step): string => $step['to']['value'], $data['history']));
        self::assertSame('Envoyée par mail', $data['history'][1]['note']);
        self::assertNotNull($data['applied_at']);
    }

    public function testAForbiddenTransitionIsRefusedAndLeavesNoTrace(): void
    {
        $id = $this->application();

        $this->send('POST', "/api/v1/applications/$id/status", ['status' => 'offer']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_transition', $this->json()['code']);
        self::assertStringContainsString('Transition interdite', $this->json()['data']['message']);
        $application = $this->reload(\App\Entity\Application::class, $id);
        self::assertSame(ApplicationStatus::Draft, $application->getStatus());
    }

    public function testAStatusChangeValidatesItsFields(): void
    {
        $id = $this->application();
        $tomorrowPlus = new \DateTimeImmutable('+10 days')->format('Y-m-d');

        foreach ([
            [['status' => 'embauche'], 'status'],
            [[], 'status'],
            [['status' => 'applied', 'at' => 'pas une date'], 'at'],
            [['status' => 'applied', 'at' => $tomorrowPlus], 'at'],
            [['status' => 'applied', 'note' => str_repeat('a', 501)], 'note'],
            [['status' => 'applied', 'note' => ['x']], 'note'],
        ] as [$body, $field]) {
            $this->send('POST', "/api/v1/applications/$id/status", $body);

            self::assertResponseStatusCodeSame(422, json_encode($body));
            self::assertArrayHasKey($field, $this->json()['data']['errors']);
        }
        self::assertSame(ApplicationStatus::Draft, $this->reload(\App\Entity\Application::class, $id)->getStatus());
    }

    // --- Modifier les informations --------------------------------------------------------------

    public function testAPartialUpdateOnlyTouchesTheGivenFields(): void
    {
        $id = $this->application();
        $this->send('PATCH', "/api/v1/applications/$id", ['notes' => 'Première note', 'recruiter_name' => 'Camille Martin', 'channel' => 'email', 'follow_up_at' => '2026-12-20']);
        self::assertResponseStatusCodeSame(200);

        $this->send('PATCH', "/api/v1/applications/$id", ['notes' => 'Note corrigée']);

        $data = $this->json()['data']['application'];
        self::assertSame('Note corrigée', $data['notes']);
        self::assertSame('Camille Martin', $data['recruiter_name'], 'Un champ absent ne change pas.');
        self::assertSame('email', $data['channel']['value']);
        self::assertStringStartsWith('2026-12-20', (string) $data['follow_up_at']);
    }

    public function testNullOrEmptyClearsAField(): void
    {
        $id = $this->application();
        $this->send('PATCH', "/api/v1/applications/$id", ['notes' => 'À effacer', 'channel' => 'email', 'follow_up_at' => '2026-12-20', 'recruiter_contact' => 'a@b.fr']);

        $this->send('PATCH', "/api/v1/applications/$id", ['notes' => '   ', 'channel' => null, 'follow_up_at' => '', 'recruiter_contact' => null]);

        $data = $this->json()['data']['application'];
        self::assertNull($data['notes']);
        self::assertNull($data['channel']);
        self::assertNull($data['follow_up_at']);
        self::assertNull($data['recruiter_contact']);
    }

    public function testAnUpdateValidatesEveryFieldAndChangesNothingOnError(): void
    {
        $id = $this->application();
        $this->send('PATCH', "/api/v1/applications/$id", ['notes' => 'Intacte']);

        foreach ([
            [['channel' => 'pigeon'], 'channel'],
            [['follow_up_at' => 'demain-ish'], 'follow_up_at'],
            [['notes' => str_repeat('a', 20001)], 'notes'],
            [['recruiter_name' => str_repeat('a', 256)], 'recruiter_name'],
            [['notes' => 42], 'notes'],
            [['statut' => 'applied'], 'statut'],
            [['cv' => 'cv-inconnu'], 'cv'],
        ] as [$body, $field]) {
            $this->send('PATCH', "/api/v1/applications/$id", $body + ['recruiter_contact' => 'ne doit pas passer']);

            self::assertResponseStatusCodeSame(422, json_encode($body));
            self::assertArrayHasKey($field, $this->json()['data']['errors']);
        }

        $application = $this->reload(\App\Entity\Application::class, $id);
        self::assertSame('Intacte', $application->getNotes());
        self::assertNull($application->getRecruiterContact(), 'Une erreur n\'enregistre rien, même les champs valides.');
    }

    public function testTheStatusCannotBeChangedThroughTheUpdateRoute(): void
    {
        $id = $this->application();

        $this->send('PATCH', "/api/v1/applications/$id", ['status' => 'accepted']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ApplicationStatus::Draft, $this->reload(\App\Entity\Application::class, $id)->getStatus());
    }

    public function testStoredUserTextIsNeverInterpretedByTheApi(): void
    {
        $id = $this->application();
        $this->send('PATCH', "/api/v1/applications/$id", ['notes' => '<script>alert(1)</script> {{7*7}}']);

        $this->client->request('GET', "/api/v1/applications/$id", server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        self::assertSame('<script>alert(1)</script> {{7*7}}', $this->json()['data']['application']['notes'], 'Stocké et rendu tel quel : le front l\'affiche comme du texte.');
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testNothingIsSentToARecruiterOrAnyone(): void
    {
        $id = $this->application();

        $this->send('POST', "/api/v1/applications/$id/status", ['status' => 'applied']);
        $this->send('PATCH', "/api/v1/applications/$id", ['message_sent' => 'Bonjour', 'recruiter_contact' => 'rh@exemple.fr']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, self::getContainer()->get(JobRepository::class)->count([]), 'Les écritures ne créent aucune offre.');
    }
}
