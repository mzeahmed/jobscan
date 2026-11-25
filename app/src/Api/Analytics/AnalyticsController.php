<?php

declare(strict_types=1);

namespace App\Api\Analytics;

use App\Analytics\Rate;
use App\Analytics\Window;
use App\Analytics\AnalyticsService;
use App\Api\Shared\AbstractApiController;
use App\Api\Shared\InvalidQueryException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `GET /api/v1/analytics?since=30d|90d|365d|all` — parcours des candidatures, répartitions et rendement des sources.
 *
 * Un pourcentage n'est donné que sur un échantillon assez grand (`min_sample`) : en dessous, le taux vaut `null` et le
 * front affiche « x/y », jamais un pourcentage trompeur.
 */
final class AnalyticsController extends AbstractApiController
{
    private const array WINDOWS = ['30d', '90d', '365d', 'all'];

    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    #[Route(
        '/analytics',
        name: 'api_analytics',
        methods: ['GET']
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $choice = $request->query->get('since', '90d');

        if (!\in_array($choice, self::WINDOWS, true)) {
            return $this->invalidQuery(new InvalidQueryException(['since' => 'Valeur attendue : ' . implode(', ', self::WINDOWS) . '.']));
        }

        $now = new \DateTimeImmutable();
        $since = Window::since($choice, $now);
        $report = $this->analytics->report($since === false ? null : $since, $now);
        $funnel = $report->funnel;
        $rows = static fn (array $list): array => array_map(static fn ($row): array => [
            'label' => $row->label,
            'sent' => $row->sent,
            'responded' => $row->responded,
            'interviews' => $row->interviews,
            'offers' => $row->offers,
            'response_rate' => Rate::of($row->responded, $row->sent),
            'interview_rate' => Rate::of($row->interviews, $row->sent),
            'offer_rate' => Rate::of($row->offers, $row->sent),
        ], $list);

        return $this->success([
            'window' => $choice,
            'since' => $report->since?->format(DATE_ATOM),
            'generated_at' => $report->generatedAt->format(DATE_ATOM),
            'min_sample' => Rate::MIN_SAMPLE,
            'funnel' => [
                'sent' => $funnel->sent,
                'responded' => $funnel->responded,
                'interviews' => $funnel->interviews,
                'offers' => $funnel->offers,
                'accepted' => $funnel->accepted,
                'rejected' => $funnel->rejected,
                'no_response' => $funnel->noResponse,
                'median_days_to_response' => $funnel->medianDaysToResponse,
                'response_rate' => Rate::of($funnel->responded, $funnel->sent),
                'interview_rate' => Rate::of($funnel->interviews, $funnel->sent),
                'offer_rate' => Rate::of($funnel->offers, $funnel->sent),
            ],
            'breakdowns' => array_map($rows, $report->breakdowns),
            'sourcing' => array_map(static fn ($row): array => [
                'source' => $row->source,
                'collected' => $row->collected,
                'compatible' => $row->compatible,
                'notified' => $row->notified,
                'tracked' => $row->tracked,
                'applied' => $row->applied,
            ], $report->sourcing),
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
