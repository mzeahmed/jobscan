import type {Route} from '@playwright/test';

interface Rates {
  response_rate: number | null;
  interview_rate: number | null;
  offer_rate: number | null;
}

export interface MockBreakdownRow extends Rates {
  label: string;
  sent: number;
  responded: number;
  interviews: number;
  offers: number;
}

/** Rapport simulé : mêmes champs que `GET /api/v1/analytics` (docs/API.md). */
export interface MockReport {
  min_sample: number;
  funnel: Rates & {
    sent: number;
    responded: number;
    interviews: number;
    offers: number;
    accepted: number;
    rejected: number;
    no_response: number;
    median_days_to_response: number | null;
  };
  breakdowns: Record<'source' | 'cv' | 'profile' | 'channel', MockBreakdownRow[]>;
  sourcing: {
    source: string;
    collected: number;
    compatible: number;
    notified: number;
    tracked: number;
    applied: number;
  }[];
}

export interface AnalyticsMock {
  report: MockReport;
  /** Fenêtres demandées (`since`), dans l'ordre. */
  queries: string[];
  status: number;
}

export function emptyReport(): MockReport {
  return {
    min_sample: 5,
    funnel: {
      sent: 0,
      responded: 0,
      interviews: 0,
      offers: 0,
      accepted: 0,
      rejected: 0,
      no_response: 0,
      median_days_to_response: null,
      response_rate: null,
      interview_rate: null,
      offer_rate: null
    },
    breakdowns: {source: [], cv: [], profile: [], channel: []},
    sourcing: []
  };
}

/** Un échantillon de 8 candidatures (taux affichés) et un de 2 (taux masqués : « x/y »). */
export function sampleReport(): MockReport {
  return {
    min_sample: 5,
    funnel: {
      sent: 8,
      responded: 4,
      interviews: 2,
      offers: 1,
      accepted: 1,
      rejected: 2,
      no_response: 3,
      median_days_to_response: 6,
      response_rate: 0.5,
      interview_rate: 0.25,
      offer_rate: 0.125
    },
    breakdowns: {
      source: [
        {
          label: 'lever',
          sent: 6,
          responded: 3,
          interviews: 2,
          offers: 1,
          response_rate: 0.5,
          interview_rate: 0.333,
          offer_rate: 0.167
        },
        {
          label: 'rss',
          sent: 2,
          responded: 1,
          interviews: 0,
          offers: 0,
          response_rate: null,
          interview_rate: null,
          offer_rate: null
        }
      ],
      cv: [
        {
          label: 'non renseigné',
          sent: 8,
          responded: 4,
          interviews: 2,
          offers: 1,
          response_rate: 0.5,
          interview_rate: 0.25,
          offer_rate: 0.125
        }
      ],
      profile: [],
      channel: []
    },
    sourcing: [{source: 'lever', collected: 120, compatible: 30, notified: 12, tracked: 7, applied: 6}]
  };
}

const success = (data: Record<string, unknown>) => ({code: 'success', success: true, data: {message: 'OK', ...data}});

/** Simule `GET /analytics`. Retourne `false` si le chemin n'est pas le sien. */
export async function handleAnalytics(
  mock: AnalyticsMock,
  route: Route,
  method: string,
  path: string,
  url: URL
): Promise<boolean> {
  if (method !== 'GET' || path !== '/analytics') {
    return false;
  }

  const since = url.searchParams.get('since') ?? '';
  mock.queries.push(since);

  if (mock.status !== 0) {
    await route.fulfill({
      status: mock.status,
      json: {code: 'internal_error', success: false, data: {message: 'Erreur interne du serveur.'}}
    });

    return true;
  }

  await route.fulfill({
    json: success({
      window: since,
      since: since === 'all' ? null : '2026-08-01T00:00:00+00:00',
      generated_at: '2026-11-25T10:00:00+00:00',
      ...mock.report
    })
  });

  return true;
}
