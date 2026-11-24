<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Job;
use App\Entity\Application;
use App\Tracking\InputReader;
use App\Tracking\JobUserState;
use App\Profile\ProfileRegistry;
use App\Repository\JobRepository;
use App\Tracking\ApplicationStatus;
use App\Tracking\ApplicationChannel;
use App\Tracking\ApplicationManager;
use App\Profile\InvalidProfileException;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\ApplicationRepository;
use App\Tracking\InvalidApplicationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\ApplicationStatusChangeRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Suivi des candidatures. Toute écriture est un POST protégé par un jeton CSRF propre à l'action et à l'objet ;
 * toutes les saisies passent par les mêmes règles que les commandes (`ApplicationManager`, `InputReader`).
 */
final class TrackingController extends AbstractController
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ApplicationStatusChangeRepository $history,
        private readonly JobRepository $jobs,
        private readonly ApplicationManager $manager,
        private readonly ProfileRegistry $profiles,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        '/applications',
        name: 'app_applications',
        methods: ['GET']
    )]
    public function index(Request $request): Response
    {
        $filter = ApplicationStatus::tryFrom($request->query->getString('status'));

        return $this->render('application/index.html.twig', [
            'applications' => $this->applications->findByStatuses($filter === null ? null : [$filter], 200),
            'counts' => $this->applications->countByStatus(),
            'statuses' => ApplicationStatus::cases(),
            'filter' => $filter,
        ]);
    }

    #[Route(
        '/applications/{id}',
        name: 'app_application_show',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function show(int $id): Response
    {
        $application = $this->application($id);

        return $this->render('application/show.html.twig', [
            'application' => $application,
            'history' => $this->history->findHistory($application),
            'transitions' => $application->getStatus()->allowedTransitions(),
            'channels' => ApplicationChannel::cases(),
            'cvs' => $this->cvChoices(),
        ]);
    }

    #[Route(
        '/applications/{id}/status',
        name: 'app_application_status',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function status(int $id, Request $request): RedirectResponse
    {
        $application = $this->application($id);
        $this->requireCsrf('application_status_' . $id, $request);

        $status = ApplicationStatus::tryFrom($request->request->getString('status'));
        if ($status === null) {
            $this->addFlash('error', 'Statut inconnu.');

            return $this->redirectToRoute('app_application_show', ['id' => $id]);
        }

        try {
            $now = new \DateTimeImmutable();
            $at = InputReader::date($request->request->getString('at'), $now);
            $this->manager->changeStatus($application, $status, $at, InputReader::optionalString($request->request->getString('note')));
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Statut : %s.', $status->label()));
        } catch (\InvalidArgumentException | InvalidApplicationException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_application_show', ['id' => $id]);
    }

    #[Route(
        '/applications/{id}/details',
        name: 'app_application_details',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function details(int $id, Request $request): RedirectResponse
    {
        $application = $this->application($id);
        $this->requireCsrf('application_details_' . $id, $request);
        $input = $request->request;

        try {
            $channel = InputReader::optionalString($input->getString('channel'));
            if ($channel !== null && ApplicationChannel::tryFrom($channel) === null) {
                throw new InvalidApplicationException('Canal inconnu.');
            }
            $cv = InputReader::optionalString($input->getString('cv'));
            $followUp = InputReader::optionalString($input->getString('follow_up'));

            if ($cv === null) {
                $application->setCvVersion(null, null);
            } elseif ($cv !== $application->getCvVersionId()) {
                $this->manager->attachCv($application, $cv);
            }
            $application->setChannel($channel === null ? null : ApplicationChannel::from($channel));
            $application->setRecruiterName($input->getString('recruiter_name'));
            $application->setRecruiterContact($input->getString('recruiter_contact'));
            $application->setMessageSent($input->getString('message_sent'));
            $application->setNotes($input->getString('notes'));
            $application->setFollowUpAt($followUp === null ? null : InputReader::anyDate($followUp));
            $application->touch(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->addFlash('success', 'Informations enregistrées.');
        } catch (\InvalidArgumentException | InvalidApplicationException $e) {
            $this->entityManager->clear();
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_application_show', ['id' => $id]);
    }

    #[Route(
        '/job/{id}/track',
        name: 'app_job_track',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function track(int $id, Request $request): RedirectResponse
    {
        $job = $this->job($id);
        $this->requireCsrf('job_track_' . $id, $request);

        $existing = $this->applications->findOneByJob($job);
        if ($existing !== null) {
            return $this->redirectToRoute('app_application_show', ['id' => $existing->getId()]);
        }

        $application = $this->manager->start($job, new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->addFlash('success', 'Candidature ouverte (brouillon) : rien n\'a été envoyé.');

        return $this->redirectToRoute('app_application_show', ['id' => $application->getId()]);
    }

    #[Route(
        '/job/{id}/decide',
        name: 'app_job_decide',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function decide(int $id, Request $request): RedirectResponse
    {
        $job = $this->job($id);
        $this->requireCsrf('job_decide_' . $id, $request);

        $value = $request->request->getString('state');
        $state = $value === 'none' ? null : JobUserState::tryFrom($value);
        if ($value !== 'none' && $state === null) {
            $this->addFlash('error', 'Décision inconnue.');

            return $this->redirectToRoute('app_job');
        }

        $this->manager->decide($job, $state, new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->addFlash('success', $state === null ? 'Décision retirée.' : sprintf('Offre : %s.', mb_strtolower($state->label())));

        return $this->redirectToRoute('app_job');
    }

    private function requireCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function application(int $id): Application
    {
        return $this->applications->find($id) ?? throw new NotFoundHttpException('Candidature introuvable.');
    }

    private function job(int $id): Job
    {
        return $this->jobs->find($id) ?? throw new NotFoundHttpException('Offre introuvable.');
    }

    /** @return list<array{id: string, label: string}> */
    private function cvChoices(): array
    {
        try {
            $candidate = $this->profiles->candidate();
        } catch (InvalidProfileException) {
            return [];
        }

        return array_map(static fn ($cv): array => ['id' => $cv->id, 'label' => $cv->label], $candidate->cvVersions ?? []);
    }
}
