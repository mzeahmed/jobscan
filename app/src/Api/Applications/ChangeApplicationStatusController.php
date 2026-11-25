<?php

declare(strict_types=1);

namespace App\Api\Applications;

use App\Api\Shared\JsonBody;
use App\Tracking\InputReader;
use App\Tracking\ApplicationStatus;
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
 * `POST /api/v1/applications/{id}/status` — `{"status": "...", "at"?: "2026-12-01", "note"?: "..."}` : consigne un changement
 * de statut **déclaré par l'utilisateur** (rien n'est envoyé à un recruteur). Les transitions interdites et les dates
 * futures sont refusées (422) ; chaque changement entre dans l'historique.
 */
final class ChangeApplicationStatusController extends AbstractApiController
{
    private const int NOTE_LENGTH = 500;

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ApplicationStatusChangeRepository $history,
        private readonly ApplicationManager $manager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        '/applications/{id}/status',
        name: 'api_applications_status',
        requirements: ['id' => '\d{1,18}'],
        methods: ['POST']
    )]
    public function __invoke(int $id, Request $request): JsonResponse
    {
        $application = $this->applications->find($id);

        if ($application === null) {
            return $this->error(['message' => 'Candidature introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $body = JsonBody::read($request);
        $now = new \DateTimeImmutable();
        $errors = [];

        $value = $body['status'] ?? null;
        $status = \is_string($value) ? ApplicationStatus::tryFrom($value) : null;
        if ($status === null) {
            $errors['status'] = 'Valeur attendue : ' . implode(', ', array_column(ApplicationStatus::cases(), 'value')) . '.';
        }

        $note = $body['note'] ?? null;
        if ($note !== null && !\is_string($note)) {
            $errors['note'] = 'Texte attendu.';
        } elseif (\is_string($note) && mb_strlen($note) > self::NOTE_LENGTH) {
            $errors['note'] = sprintf('%d caractères au maximum.', self::NOTE_LENGTH);
        }

        $at = $now;
        try {
            $at = InputReader::date($body['at'] ?? null, $now);
        } catch (\InvalidArgumentException $e) {
            $errors['at'] = $e->getMessage();
        }

        if ($errors !== [] || $status === null) {
            return $this->invalidInput(new InvalidInputException($errors));
        }

        try {
            $this->manager->changeStatus($application, $status, $at, \is_string($note) ? InputReader::optionalString($note) : null);
        } catch (InvalidApplicationException $e) {
            return $this->error(['error' => 'invalid_transition', 'message' => $e->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->flush();

        return $this->success(
            ['message' => sprintf('Statut : %s.', $status->label()), 'application' => ApplicationPresenter::detail($application, $this->history->findHistory($application), $now)],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
