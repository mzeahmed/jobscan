<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Entity\Application;
use App\Tracking\JobUserState;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationManager;
use App\Repository\ApplicationRepository;

final class TrackingWebTest extends WebTestCaseBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    private function applicationRepository(): ApplicationRepository
    {
        return self::getContainer()->get(ApplicationRepository::class);
    }

    private function trackedApplication(string $url = 'https://jobs.example/1'): Application
    {
        $application = self::getContainer()->get(ApplicationManager::class)->start($this->persistJob('Développeur PHP', $url), new \DateTimeImmutable('-10 days'));
        $this->entityManager->flush();

        return $application;
    }

    public function testStartTrackingAJobFromTheList(): void
    {
        $job = $this->persistJob();
        $page = $this->client->request('GET', '/job');

        $this->client->request('POST', '/job/' . $job->getId() . '/track', ['_token' => $this->token($page, '/track')]);

        self::assertResponseRedirects('/applications/1');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'rien n\'a été envoyé');
        self::assertSame(ApplicationStatus::Draft, $this->applicationRepository()->find(1)->getStatus());

        $list = $this->client->request('GET', '/job');
        self::assertCount(0, $list->filter('form[action*="/track"]'), 'Une offre déjà suivie propose la candidature, pas « Suivre ».');
        self::assertSelectorExists('a[href="/applications/1"]');
    }

    public function testTrackingTwiceReusesTheApplication(): void
    {
        $job = $this->persistJob();
        $token = $this->token($this->client->request('GET', '/job'), '/track');

        $this->client->request('POST', '/job/' . $job->getId() . '/track', ['_token' => $token]);
        $this->client->request('POST', '/job/' . $job->getId() . '/track', ['_token' => $token]);

        self::assertResponseRedirects('/applications/1');
        self::assertSame(1, $this->applicationRepository()->count([]));
    }

    public function testEveryWriteRefusesAMissingOrForgedCsrfToken(): void
    {
        $application = $this->trackedApplication();
        $job = $application->getJob();
        $otherJob = $this->persistJob('Autre offre', 'https://jobs.example/2');
        $tokenForOtherJob = $this->token($this->client->request('GET', '/job'), '/job/' . $otherJob->getId() . '/decide');

        $writes = [
            ['/job/' . $otherJob->getId() . '/track', []],
            ['/job/' . $job->getId() . '/decide', ['state' => 'ignored']],
            ['/applications/' . $application->getId() . '/status', ['status' => 'applied']],
            ['/applications/' . $application->getId() . '/details', ['notes' => 'x']],
        ];
        foreach ($writes as [$path, $data]) {
            $this->client->request('POST', $path, $data);
            self::assertResponseStatusCodeSame(403, $path . ' sans jeton');
            $this->client->request('POST', $path, $data + ['_token' => 'forgé']);
            self::assertResponseStatusCodeSame(403, $path . ' avec un faux jeton');
        }

        // Le jeton valide d'une autre offre ne vaut pas pour celle-ci.
        $this->client->request('POST', '/job/' . $job->getId() . '/decide', ['state' => 'ignored', '_token' => $tokenForOtherJob]);
        self::assertResponseStatusCodeSame(403);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find($job::class, $job->getId())->getUserState());
        self::assertSame(ApplicationStatus::Draft, $this->applicationRepository()->find($application->getId())->getStatus());
        self::assertSame(0, $this->applicationRepository()->count(['job' => $otherJob]));
    }

    public function testWritesAreNotAvailableThroughGet(): void
    {
        $application = $this->trackedApplication();

        foreach (['/applications/1/status', '/applications/1/details', '/job/1/track', '/job/1/decide'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(405, $path);
        }
        self::assertSame(ApplicationStatus::Draft, $application->getStatus());
    }

    public function testDecisionsHideAndRestoreJobsFromTheDefaultList(): void
    {
        $job = $this->persistJob('Offre à ignorer');
        $page = $this->client->request('GET', '/job');
        $token = $this->token($page, '/decide');

        $this->client->request('POST', '/job/' . $job->getId() . '/decide', ['state' => 'ignored', '_token' => $token]);
        self::assertResponseRedirects('/job');

        $default = $this->client->request('GET', '/job');
        self::assertStringNotContainsString('Offre à ignorer', $default->text());
        $ignored = $this->client->request('GET', '/job?state=ignored');
        self::assertStringContainsString('Offre à ignorer', $ignored->text());
        self::assertStringContainsString('Ignorée', $ignored->text());
        $all = $this->client->request('GET', '/job?state=all');
        self::assertStringContainsString('Offre à ignorer', $all->text());

        $this->client->request('POST', '/job/' . $job->getId() . '/decide', ['state' => 'none', '_token' => $this->token($ignored, '/decide')]);
        self::assertStringContainsString('Offre à ignorer', $this->client->request('GET', '/job')->text());
    }

    public function testSavedJobsStayInTheDefaultListAndAnUnknownDecisionIsRefused(): void
    {
        $job = $this->persistJob('Offre sauvegardée');
        $token = $this->token($this->client->request('GET', '/job'), '/decide');

        $this->client->request('POST', '/job/' . $job->getId() . '/decide', ['state' => 'saved', '_token' => $token]);
        self::assertStringContainsString('Offre sauvegardée', $this->client->request('GET', '/job')->text());
        self::assertSame(JobUserState::Saved, $this->entityManager->find($job::class, $job->getId())->getUserState());

        $this->client->request('POST', '/job/' . $job->getId() . '/decide', ['state' => 'supprimée', '_token' => $token]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Décision inconnue');
    }

    public function testChangeTheStatusAndSeeTheHistory(): void
    {
        $application = $this->trackedApplication();
        $page = $this->client->request('GET', '/applications/' . $application->getId());
        $form = $page->filter('form[action$="/status"]')->form(['status' => 'applied', 'note' => 'Envoyée par email']);

        $this->client->submit($form);
        $page = $this->client->followRedirect();

        self::assertSelectorTextContains('.flash--success', 'Postulé');
        self::assertStringContainsString('Brouillon → Postulé', preg_replace('/\s+/', ' ', $page->filter('.history')->text()) ?? '');
        self::assertStringContainsString('Envoyée par email', $page->filter('.history')->text());
        self::assertNotNull($this->applicationRepository()->find($application->getId())->getAppliedAt());
    }

    public function testOnlyAllowedTransitionsAreOfferedAndForbiddenOnesAreRefused(): void
    {
        $application = $this->trackedApplication();
        $page = $this->client->request('GET', '/applications/' . $application->getId());
        $options = $page->filter('form[action$="/status"] select option')->each(static fn ($option): string => (string) $option->attr('value'));
        self::assertSame(['applied', 'withdrawn'], $options);

        $this->client->request('POST', '/applications/' . $application->getId() . '/status', ['status' => 'accepted', '_token' => $this->token($page, '/status')]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash--error', 'Transition interdite');
        self::assertSame(ApplicationStatus::Draft, $this->applicationRepository()->find($application->getId())->getStatus());
    }

    public function testInvalidStatusAndDatesAreRefused(): void
    {
        $application = $this->trackedApplication();
        $token = $this->token($this->client->request('GET', '/applications/' . $application->getId()), '/status');
        $path = '/applications/' . $application->getId() . '/status';

        foreach ([['status' => 'embauché'], ['status' => 'applied', 'at' => 'pas une date'], ['status' => 'applied', 'at' => '2099-01-01']] as $data) {
            $this->client->request('POST', $path, $data + ['_token' => $token]);
            $this->client->followRedirect();
            self::assertSelectorExists('.flash--error');
        }
        self::assertSame(ApplicationStatus::Draft, $this->applicationRepository()->find($application->getId())->getStatus());
    }

    public function testAFinalStatusOffersNoTransition(): void
    {
        $application = $this->trackedApplication();
        $manager = self::getContainer()->get(ApplicationManager::class);
        $manager->changeStatus($application, ApplicationStatus::Withdrawn, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->client->request('GET', '/applications/' . $application->getId());

        self::assertSelectorNotExists('form[action$="/status"]');
        self::assertSelectorTextContains('.empty', 'statut final');
    }

    public function testSaveTheDetails(): void
    {
        $application = $this->trackedApplication();
        $page = $this->client->request('GET', '/applications/' . $application->getId());
        $form = $page->filter('form[action$="/details"]')->form([
            'recruiter_name' => ' Camille Martin ',
            'recruiter_contact' => 'camille@exemple.fr',
            'channel' => 'email',
            'follow_up' => '2026-12-20',
            'message_sent' => 'Bonjour, je candidate.',
            'notes' => 'Rappeler jeudi.',
        ]);

        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash--success', 'enregistrées');
        $this->entityManager->clear();
        $saved = $this->applicationRepository()->find($application->getId());
        self::assertSame('Camille Martin', $saved->getRecruiterName());
        self::assertSame('camille@exemple.fr', $saved->getRecruiterContact());
        self::assertSame('email', $saved->getChannel()?->value);
        self::assertSame('2026-12-20', $saved->getFollowUpAt()?->format('Y-m-d'));
        self::assertSame('Bonjour, je candidate.', $saved->getMessageSent());
        self::assertSame('Rappeler jeudi.', $saved->getNotes());
    }

    public function testInvalidDetailsChangeNothing(): void
    {
        $application = $this->trackedApplication();
        $application->setNotes('Note d\'origine');
        $this->entityManager->flush();
        $token = $this->token($this->client->request('GET', '/applications/' . $application->getId()), '/details');
        $path = '/applications/' . $application->getId() . '/details';

        foreach ([['channel' => 'pigeon'], ['cv' => 'cv-inconnu'], ['follow_up' => 'pas une date']] as $data) {
            $this->client->request('POST', $path, $data + ['notes' => 'Modifiée', '_token' => $token]);
            $this->client->followRedirect();
            self::assertSelectorExists('.flash--error');
        }

        $this->entityManager->clear();
        self::assertSame('Note d\'origine', $this->applicationRepository()->find($application->getId())->getNotes());
    }

    public function testPersonalAndAdvertTextIsEscapedAndUnsafeLinksAreNotClickable(): void
    {
        $application = $this->trackedApplication('javascript:alert(document.cookie)');
        $application->setNotes('<script>alert(1)</script><img src=x onerror=alert(2)>');
        $application->setRecruiterName('"><svg onload=alert(3)>');
        $this->entityManager->flush();

        $page = $this->client->request('GET', '/applications/' . $application->getId());
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<svg onload', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('javascript:', $html, 'Une URL d\'annonce non http(s) n\'est jamais un lien.');
        self::assertSame('#', $page->selectLink('Voir l\'offre')->attr('href'));
    }

    public function testExternalLinksDoNotLeakTheOpener(): void
    {
        $application = $this->trackedApplication('https://jobs.example/offre');

        $link = $this->client->request('GET', '/applications/' . $application->getId())->selectLink('Voir l\'offre');

        self::assertSame('https://jobs.example/offre', $link->attr('href'));
        self::assertStringContainsString('noopener', (string) $link->attr('rel'));
        self::assertStringContainsString('noreferrer', (string) $link->attr('rel'));
    }

    public function testListingAndFilteringApplications(): void
    {
        $manager = self::getContainer()->get(ApplicationManager::class);
        $first = $this->trackedApplication();
        $second = $manager->start($this->persistJob('Autre poste', 'https://jobs.example/9'), new \DateTimeImmutable('-5 days'));
        $manager->changeStatus($second, ApplicationStatus::Applied, new \DateTimeImmutable('-4 days'));
        $this->entityManager->flush();

        $all = $this->client->request('GET', '/applications');
        self::assertCount(2, $all->filter('tbody tr'));

        $applied = $this->client->request('GET', '/applications?status=applied');
        self::assertCount(1, $applied->filter('tbody tr'));
        self::assertStringContainsString('Autre poste', $applied->text());

        $unknown = $this->client->request('GET', '/applications?status=n-importe-quoi');
        self::assertCount(2, $unknown->filter('tbody tr'), 'Un filtre inconnu est ignoré.');
        self::assertSame(ApplicationStatus::Draft, $first->getStatus());
    }

    public function testDueFollowUpsAreHighlightedInTheListAndOnTheDetail(): void
    {
        $manager = self::getContainer()->get(ApplicationManager::class);
        $due = $manager->start($this->persistJob('Relance due', 'https://jobs.example/due'), new \DateTimeImmutable('-20 days'));
        $manager->changeStatus($due, ApplicationStatus::Applied, new \DateTimeImmutable('-15 days'));
        $due->setFollowUpAt(new \DateTimeImmutable('-2 days'));
        $due->markReminded(new \DateTimeImmutable('-1 day'));
        $future = $manager->start($this->persistJob('Relance future', 'https://jobs.example/future'), new \DateTimeImmutable('-20 days'));
        $manager->changeStatus($future, ApplicationStatus::Applied, new \DateTimeImmutable('-15 days'));
        $future->setFollowUpAt(new \DateTimeImmutable('+5 days'));
        $this->entityManager->flush();

        $list = $this->client->request('GET', '/applications');

        self::assertSelectorTextContains('.flash--error', '1 relance à faire');
        self::assertCount(1, $list->filter('td strong[title="Relance due"]'));

        $this->client->request('GET', '/applications/' . $due->getId());
        self::assertSelectorTextContains('small', 'rappel Telegram envoyé le');
        $this->client->request('GET', '/applications/' . $future->getId());
        self::assertSelectorNotExists('small');
    }

    public function testUnknownApplicationsAndJobsAreNotFound(): void
    {
        $this->client->request('GET', '/applications/999');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/job/999/track', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }
}
