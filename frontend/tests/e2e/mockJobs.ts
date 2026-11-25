import type {Route} from '@playwright/test';

/** Offre simulée : mêmes champs que `GET /api/v1/jobs` (docs/API.md). */
export interface MockJob {
  id: number;
  title: string | null;
  url: string | null;
  source: string;
  company: string | null;
  location: string | null;
  remote: boolean;
  freelance: boolean;
  contract_type: string;
  seniority: string;
  stack: string[];
  score: number | null;
  excerpt: string;
  created_at: string;
  published_at: string | null;
  notified_at: string | null;
  user_state: {value: string; label: string} | null;
  application_id: number | null;
}

const LABELS: Record<string, string> = {saved: 'Sauvegardée', ignored: 'Ignorée', archived: 'Archivée'};
const PER_PAGE = 10;

export function makeJob(id: number, overrides: Partial<MockJob> = {}): MockJob {
  return {
    id,
    title: `Développeur PHP ${id}`,
    url: `https://jobs.example/${id}`,
    source: id % 2 === 0 ? 'rss' : 'lever',
    company: 'Exemple SAS',
    location: 'Paris',
    remote: false,
    freelance: false,
    contract_type: 'cdi',
    seniority: 'mid',
    stack: ['php'],
    score: 40 + (id % 60),
    excerpt: `Description de l'offre ${id}.`,
    created_at: '2026-11-20T10:00:00+00:00',
    published_at: null,
    notified_at: null,
    user_state: null,
    application_id: null,
    ...overrides
  };
}

/** Requêtes reçues par la liste (chaînes de paramètres), pour vérifier ce que le front demande vraiment. */
export interface JobsMock {
  jobs: MockJob[];
  listQueries: string[];
  /** Statut forcé pour les écritures (ex. 500) ; 0 : normal. */
  writeStatus: number;
  /** Statut forcé pour la liste ; 0 : normal. */
  listStatus: number;
  nextApplicationId: number;
}

function json(route: Route, status: number, body: unknown) {
  return route.fulfill({status, json: body});
}

function success(data: Record<string, unknown>) {
  return {code: 'success', success: true, data: {message: 'OK', ...data}};
}

function failure(code: string, message: string) {
  return {code, success: false, data: {message}};
}

/** Simule `GET /jobs`, `POST /jobs/{id}/application` et `PUT /jobs/{id}/decision`. Retourne `false` si le chemin n'est pas le leur. */
export async function handleJobs(
  mock: JobsMock,
  route: Route,
  method: string,
  path: string,
  url: URL
): Promise<boolean> {
  if (method === 'GET' && path === '/jobs') {
    mock.listQueries.push(url.searchParams.toString());

    if (mock.listStatus !== 0) {
      await json(route, mock.listStatus, failure('internal_error', 'Erreur interne du serveur.'));

      return true;
    }

    const query = url.searchParams;
    const state = query.get('state') ?? '';
    let items = mock.jobs.filter((job) => {
      if (state === 'all') {
        return true;
      }

      return state === '' ? job.user_state === null : job.user_state?.value === state;
    });
    items = items.filter((job) => (query.get('source') ? job.source === query.get('source') : true));
    items = items.filter((job) => (query.get('min_score') ? (job.score ?? 0) >= Number(query.get('min_score')) : true));
    items = items.filter((job) =>
      query.get('location') ? (job.location ?? '').includes(query.get('location') ?? '') : true
    );
    items = [...items].sort((a, b) => (query.get('sort') === 'score' ? (b.score ?? 0) - (a.score ?? 0) : b.id - a.id));

    const totalPages = Math.max(1, Math.ceil(items.length / PER_PAGE));
    const page = Math.min(Math.max(1, Number(query.get('page') ?? '1')), totalPages);

    await json(
      route,
      200,
      success({
        items: items.slice((page - 1) * PER_PAGE, page * PER_PAGE),
        pagination: {page, per_page: PER_PAGE, total: items.length, total_pages: totalPages},
        sources: [...new Set(mock.jobs.map((job) => job.source))].sort()
      })
    );

    return true;
  }

  const track = /^\/jobs\/(\d+)\/application$/.exec(path);
  const decide = /^\/jobs\/(\d+)\/decision$/.exec(path);
  const match = method === 'POST' ? track : method === 'PUT' ? decide : null;

  if (!match) {
    return false;
  }

  if (mock.writeStatus !== 0) {
    await json(route, mock.writeStatus, failure('internal_error', 'Erreur interne du serveur.'));

    return true;
  }

  const job = mock.jobs.find((candidate) => candidate.id === Number(match[1]));

  if (!job) {
    await json(route, 404, failure('not_found', 'Offre introuvable.'));

    return true;
  }

  if (method === 'POST') {
    const created = job.application_id === null;
    job.application_id ??= mock.nextApplicationId++;
    await json(route, created ? 201 : 200, success({created, message: 'Candidature ouverte (brouillon).'}));

    return true;
  }

  const body = (route.request().postDataJSON() ?? {}) as {state?: string | null};
  job.user_state = body.state ? {value: body.state, label: LABELS[body.state] ?? body.state} : null;
  await json(route, 200, success({job}));

  return true;
}
