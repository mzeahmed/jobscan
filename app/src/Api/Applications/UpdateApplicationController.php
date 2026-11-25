<?php

declare(strict_types=1);

namespace App\Api\Applications;

use App\Api\Shared\JsonBody;
use App\Tracking\ApplicationManager;
use Doctrine\ORM\EntityManagerInterface;
use App\Api\Shared\AbstractApiController;
use App\Api\Shared\InvalidInputException;
use App\Repository\ApplicationRepository;
use App\Tracking\InvalidApplicationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Repository\ApplicationStatusChangeRepository;

/**
 * `PATCH /api/v1/applications/{id}` — met à jour les informations saisies par l'utilisateur (recruteur, canal, CV, message
 * envoyé, notes, date de relance). Partielle : seuls les champs présents changent. Le statut passe par `/status`.
 */
final class UpdateApplicationController extends AbstractApiController
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ApplicationStatusChangeRepository $history,
        private readonly ApplicationManager $manager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        '/applications/{id}',
        name: 'api_applications_update',
        requirements: ['id' => '\d{1,18}'],
        methods: ['PATCH']
    )]
    public function __invoke(int $id, Request $request): JsonResponse
    {
        $application = $this->applications->find($id);

        if ($application === null) {
            return $this->error(['message' => 'Candidature introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $input = ApplicationDetailsInput::fromBody(JsonBody::read($request));
        } catch (InvalidInputException $e) {
            return $this->invalidInput($e);
        }

        $now = new \DateTimeImmutable();

        try {
            // Le CV d'abord : c'est la seule validation qui dépend du profil candidat, avant toute modification.
            if ($input->has('cv')) {
                $cv = $input->values['cv'];
                if ($cv === null) {
                    $application->setCvVersion(null, null);
                } elseif ($cv !== $application->getCvVersionId()) {
                    $this->manager->attachCv($application, $cv);
                }
            }
        } catch (InvalidApplicationException $e) {
            return $this->invalidInput(new InvalidInputException(['cv' => $e->getMessage()]));
        }

        $setters = [
            'channel' => $application->setChannel(...),
            'recruiter_name' => $application->setRecruiterName(...),
            'recruiter_contact' => $application->setRecruiterContact(...),
            'message_sent' => $application->setMessageSent(...),
            'notes' => $application->setNotes(...),
            'follow_up_at' => $application->setFollowUpAt(...),
        ];

        foreach ($setters as $field => $set) {
            if ($input->has($field)) {
                $set($input->values[$field]);
            }
        }

        $application->touch($now);
        $this->entityManager->flush();

        return $this->success(
            ['message' => 'Informations enregistrées.', 'application' => ApplicationPresenter::detail($application, $this->history->findHistory($application), $now)],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
