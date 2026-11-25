<?php

declare(strict_types=1);

namespace App\Api\Applications;

use App\Profile\ProfileRegistry;
use App\Tracking\ApplicationChannel;
use App\Profile\InvalidProfileException;
use App\Api\Shared\AbstractApiController;
use App\Repository\ApplicationRepository;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Repository\ApplicationStatusChangeRepository;

/**
 * `GET /api/v1/applications/{id}` — une candidature, son historique et les transitions encore permises, avec les choix
 * des formulaires d'édition (`options` : canaux et versions de CV déclarées dans le profil candidat).
 */
final class ShowApplicationController extends AbstractApiController
{
    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly ApplicationStatusChangeRepository $history,
        private readonly ProfileRegistry $profiles,
    ) {
    }

    #[Route(
        '/applications/{id}',
        name: 'api_applications_show',
        requirements: ['id' => '\d{1,18}'],
        methods: ['GET']
    )]
    public function __invoke(int $id): JsonResponse
    {
        $application = $this->applications->find($id);

        if ($application === null) {
            return $this->error(['message' => 'Candidature introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->success(
            [
                'application' => ApplicationPresenter::detail($application, $this->history->findHistory($application), new \DateTimeImmutable()),
                'options' => [
                    'channels' => array_map(static fn (ApplicationChannel $channel): array => ['value' => $channel->value, 'label' => $channel->label()], ApplicationChannel::cases()),
                    'cv_versions' => $this->cvVersions(),
                ],
            ],
            headers: ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Versions de CV du profil candidat. Un profil absent ou invalide donne une liste vide : l'écran se contente alors de
     * ne pas proposer de choix de CV.
     *
     * @return list<array{id: string, label: string}>
     */
    private function cvVersions(): array
    {
        try {
            $candidate = $this->profiles->candidate();
        } catch (InvalidProfileException) {
            return [];
        }

        return array_map(static fn ($cv): array => ['id' => $cv->id, 'label' => $cv->label], $candidate->cvVersions ?? []);
    }
}
