<?php

declare(strict_types=1);

namespace App\Api\Jobs;

use App\Api\Shared\JsonBody;
use App\Tracking\JobUserState;
use App\Repository\JobRepository;
use App\Tracking\ApplicationManager;
use Doctrine\ORM\EntityManagerInterface;
use App\Api\Shared\AbstractApiController;
use App\Api\Shared\InvalidInputException;
use App\Repository\ApplicationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `PUT /api/v1/jobs/{id}/decision` — `{"state": "saved" | "ignored" | "archived" | null}` : la décision de l'utilisateur
 * sur l'offre (`null` la retire). Idempotent. Une offre décidée est protégée de la purge.
 */
final class DecideJobController extends AbstractApiController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ApplicationRepository $applications,
        private readonly ApplicationManager $manager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        '/jobs/{id}/decision',
        name: 'api_jobs_decide',
        requirements: ['id' => '\d{1,18}'],
        methods: ['PUT']
    )]
    public function __invoke(int $id, Request $request): JsonResponse
    {
        $job = $this->jobs->find($id);

        if ($job === null) {
            return $this->error(['message' => 'Offre introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $body = JsonBody::read($request);
        // `state` doit être présent : `null` retire la décision, une clé absente est une erreur (pas un retrait silencieux).
        $state = null;

        if (!\array_key_exists('state', $body)) {
            $valid = false;
        } elseif ($body['state'] === null) {
            $valid = true;
        } else {
            $state = \is_string($body['state']) ? JobUserState::tryFrom($body['state']) : null;
            $valid = $state !== null;
        }

        if (!$valid) {
            return $this->invalidInput(new InvalidInputException([
                'state' => 'Valeur attendue : ' . implode(', ', array_column(JobUserState::cases(), 'value')) . ', ou null pour retirer la décision.',
            ]));
        }

        $this->manager->decide($job, $state, new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->success(
            ['job' => JobPresenter::summary($job, $this->applications->findOneByJob($job)?->getId())],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
