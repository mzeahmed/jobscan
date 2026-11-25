<?php

declare(strict_types=1);

namespace App\Api\Applications;

use App\Tracking\ApplicationStatus;
use App\Api\Shared\AbstractApiController;
use App\Repository\ApplicationRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `GET /api/v1/applications` — candidatures, les plus récemment modifiées d'abord, filtrables par statut (`?status=`).
 * Les compteurs portent sur l'ensemble des candidatures, pas sur le filtre : ils servent à construire les onglets.
 */
final class ListApplicationsController extends AbstractApiController
{
    /** Même plafond que l'interface Twig : un suivi personnel ne dépasse pas ce volume. */
    private const int LIMIT = 200;

    public function __construct(private readonly ApplicationRepository $applications)
    {
    }

    #[Route(
        '/applications',
        name: 'api_applications_list',
        methods: ['GET']
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $value = $request->query->get('status');
        $filter = null;

        if ($value !== null && $value !== '') {
            $filter = ApplicationStatus::tryFrom($value);

            if ($filter === null) {
                return $this->invalidQuery(new \App\Api\Shared\InvalidQueryException([
                    'status' => 'Valeur attendue : ' . implode(', ', array_column(ApplicationStatus::cases(), 'value')) . '.',
                ]));
            }
        }

        $now = new \DateTimeImmutable();
        $items = $this->applications->findByStatuses($filter === null ? null : [$filter], self::LIMIT);
        $counts = $this->applications->countByStatus();

        return $this->success([
            'items' => array_map(static fn ($application): array => ApplicationPresenter::summary($application, $now), $items),
            'counts' => array_map(static fn (ApplicationStatus $status): array => ApplicationPresenter::status($status) + ['count' => $counts[$status->value] ?? 0], ApplicationStatus::cases()),
            'total' => array_sum($counts),
            'due_follow_ups' => $this->applications->countDueFollowUps($now),
            'limit' => self::LIMIT,
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
