import type {Route} from '@playwright/test';

export interface MockStatus {
  value: string;
  label: string;
}

/** Candidature simulée : mêmes champs que `GET /api/v1/applications/{id}` (docs/API.md). */
export interface MockApplication {
  id: number;
  status: MockStatus;
  job: {id: number; title: string | null; company: string | null; location: string | null; url: string | null};
  channel: MockStatus | null;
  cv_version: {id: string; label: string | null} | null;
  search_profile_id: string | null;
  recruiter_name: string | null;
  recruiter_contact: string | null;
  applied_at: string | null;
  last_contact_at: string | null;
  follow_up_at: string | null;
  follow_up_due: boolean;
  follow_up_reminded_at: string | null;
  responded_at: string | null;
  message_sent: string | null;
  notes: string | null;
  status_changed_at: string;
  created_at: string;
  history: {from: MockStatus | null; to: MockStatus; note: string | null; changed_at: string}[];
}

const LABELS: Record<string, string> = {
  draft: 'Brouillon',
  applied: 'Postulé',
  interview: 'Entretien',
  technical_test: 'Test technique',
  offer: 'Proposition',
  accepted: 'Acceptée',
  rejected: 'Refusée',
  withdrawn: 'Retirée',
  no_response: 'Sans réponse'
};

/** Transitions autorisées (copie de `ApplicationStatus::allowedTransitions`). */
const TRANSITIONS: Record<string, string[]> = {
  draft: ['applied', 'withdrawn'],
  applied: ['interview', 'technical_test', 'offer', 'rejected', 'withdrawn', 'no_response'],
  no_response: ['interview', 'technical_test', 'offer', 'rejected', 'withdrawn'],
  interview: ['technical_test', 'offer', 'rejected', 'withdrawn', 'no_response'],
  technical_test: ['interview', 'offer', 'rejected', 'withdrawn', 'no_response'],
  offer: ['accepted', 'rejected', 'withdrawn'],
  accepted: [],
  rejected: [],
  withdrawn: []
};

const CHANNELS = [
  {value: 'email', label: 'E-mail'},
  {value: 'linkedin', label: 'LinkedIn'},
  {value: 'platform', label: 'Plateforme'},
  {value: 'referral', label: 'Cooptation'},
  {value: 'phone', label: 'Téléphone'},
  {value: 'other', label: 'Autre'}
];

const status = (value: string): MockStatus => ({value, label: LABELS[value] ?? value});

export function makeApplication(id: number, overrides: Partial<MockApplication> = {}): MockApplication {
  const current = overrides.status ?? status('draft');

  return {
    id,
    status: current,
    job: {
      id: id * 10,
      title: `Offre suivie ${id}`,
      company: 'Exemple SAS',
      location: 'Paris',
      url: `https://jobs.example/${id}`
    },
    channel: null,
    cv_version: null,
    search_profile_id: null,
    recruiter_name: null,
    recruiter_contact: null,
    applied_at: null,
    last_contact_at: null,
    follow_up_at: null,
    follow_up_due: false,
    follow_up_reminded_at: null,
    responded_at: null,
    message_sent: null,
    notes: null,
    status_changed_at: '2026-11-20T10:00:00+00:00',
    created_at: '2026-11-18T10:00:00+00:00',
    history: [{from: null, to: current, note: null, changed_at: '2026-11-18T10:00:00+00:00'}],
    ...overrides
  };
}

export const withStatus = (value: string) => status(value);

export interface ApplicationsMock {
  applications: MockApplication[];
  cvVersions: {id: string; label: string}[];
  /** Corps reçus par `POST …/status` et `PATCH …`, pour vérifier ce que le front envoie. */
  statusPosts: unknown[];
  patches: unknown[];
  listQueries: string[];
  listStatus: number;
  writeStatus: number;
}

function json(route: Route, statusCode: number, body: unknown) {
  return route.fulfill({status: statusCode, json: body});
}

const success = (data: Record<string, unknown>) => ({code: 'success', success: true, data: {message: 'OK', ...data}});
const failure = (code: string, message: string, extra: Record<string, unknown> = {}) => ({
  code,
  success: false,
  data: {message, ...extra}
});

function detail(application: MockApplication) {
  return {...application, allowed_transitions: (TRANSITIONS[application.status.value] ?? []).map(status)};
}

/** Simule les quatre routes candidatures. Retourne `false` si le chemin n'est pas le leur. */
export async function handleApplications(
  mock: ApplicationsMock,
  route: Route,
  method: string,
  path: string,
  url: URL
): Promise<boolean> {
  if (method === 'GET' && path === '/applications') {
    mock.listQueries.push(url.searchParams.toString());

    if (mock.listStatus !== 0) {
      await json(route, mock.listStatus, failure('internal_error', 'Erreur interne du serveur.'));

      return true;
    }

    const filter = url.searchParams.get('status');
    const items = mock.applications.filter((application) => !filter || application.status.value === filter);

    await json(
      route,
      200,
      success({
        items,
        counts: Object.keys(LABELS).map((value) => ({
          ...status(value),
          count: mock.applications.filter((application) => application.status.value === value).length
        })),
        total: mock.applications.length,
        due_follow_ups: mock.applications.filter((application) => application.follow_up_due).length,
        limit: 200
      })
    );

    return true;
  }

  const match = /^\/applications\/(\d+)(\/status)?$/.exec(path);

  if (!match || !['GET', 'POST', 'PATCH'].includes(method)) {
    return false;
  }

  const application = mock.applications.find((candidate) => candidate.id === Number(match[1]));
  const isStatus = match[2] !== undefined;

  if (method === 'GET' && !isStatus) {
    if (!application) {
      await json(route, 404, failure('not_found', 'Candidature introuvable.'));

      return true;
    }

    await json(
      route,
      200,
      success({application: detail(application), options: {channels: CHANNELS, cv_versions: mock.cvVersions}})
    );

    return true;
  }

  if ((method === 'POST') !== isStatus || method === 'GET') {
    return false;
  }

  if (!application) {
    await json(route, 404, failure('not_found', 'Candidature introuvable.'));

    return true;
  }

  if (mock.writeStatus !== 0) {
    await json(route, mock.writeStatus, failure('internal_error', 'Erreur interne du serveur.'));

    return true;
  }

  const body = (route.request().postDataJSON() ?? {}) as Record<string, unknown>;

  if (isStatus) {
    mock.statusPosts.push(body);
    const target = String(body.status ?? '');

    if (!(TRANSITIONS[application.status.value] ?? []).includes(target)) {
      await json(route, 422, failure('invalid_transition', 'Transition interdite.'));

      return true;
    }

    const next = status(target);
    const at = typeof body.at === 'string' ? `${body.at}T12:00:00+00:00` : '2026-11-25T08:00:00+00:00';
    application.history.push({
      from: application.status,
      to: next,
      note: typeof body.note === 'string' ? body.note : null,
      changed_at: at
    });
    application.status = next;
    application.status_changed_at = at;
    application.applied_at ??= target === 'applied' ? at : null;
    await json(route, 200, success({message: `Statut : ${next.label}.`, application: detail(application)}));

    return true;
  }

  mock.patches.push(body);

  if (body.recruiter_name === 'refusé') {
    await json(
      route,
      422,
      failure('unprocessable', 'Un ou plusieurs champs sont invalides.', {
        errors: {recruiter_name: '255 caractères au maximum.'}
      })
    );

    return true;
  }

  const channel = CHANNELS.find((candidate) => candidate.value === body.channel);
  const cv = mock.cvVersions.find((candidate) => candidate.id === body.cv);
  const set = <K extends keyof MockApplication>(key: K, value: MockApplication[K]) => {
    application[key] = value;
  };

  ['recruiter_name', 'recruiter_contact', 'message_sent', 'notes'].forEach((key) => {
    if (key in body) {
      set(key as 'notes', (body[key] as string | null) ?? null);
    }
  });

  if ('channel' in body) {
    application.channel = channel ?? null;
  }

  if ('cv' in body) {
    application.cv_version = cv ? {id: cv.id, label: cv.label} : null;
  }

  if ('follow_up_at' in body) {
    application.follow_up_at = typeof body.follow_up_at === 'string' ? `${body.follow_up_at}T00:00:00+00:00` : null;
  }

  await json(route, 200, success({message: 'Informations enregistrées.', application: detail(application)}));

  return true;
}
