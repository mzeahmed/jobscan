import type {Page} from '@playwright/test';
import {handleJobs, type JobsMock, type MockJob} from './mockJobs.ts';

/** État de l'API simulée, modifiable pendant un test pour reproduire une panne ou une reprise. */
export interface ApiState {
  /** Une session est ouverte côté « serveur » (cookie de rafraîchissement valide). */
  session: boolean;
  /** Le rafraîchissement répond 503 (API indisponible). */
  unavailable: boolean;
  /** Statut de la déconnexion (204 = confirmée). */
  logoutStatus: number;
  /** Corps JSON reçus par `POST /auth/login`. */
  logins: unknown[];
  /** Offres simulées et journal des requêtes (voir `mockJobs.ts`). */
  jobs: JobsMock;
}

export const ACCESS_TOKEN = 'jeton-d-acces-de-test';

const SESSION = {
  user: {id: '1', username: 'admin', roles: ['ROLE_USER']},
  access_token: ACCESS_TOKEN,
  token_type: 'Bearer',
  expires_in: 900
};

function success(data: Record<string, unknown>) {
  return {code: 'success', success: true, data: {message: 'OK', ...data}};
}

function failure(code: string, message: string) {
  return {code, success: false, data: {message}};
}

/**
 * Simule l'API Jobscan (contrat de docs/API.md) : racine, connexion, rafraîchissement, déconnexion. Tout autre
 * chemin répond 404 dans l'enveloppe. Les polices Google sont neutralisées : les tests ne dépendent pas d'Internet.
 */
export async function mockApi(
  page: Page,
  initial: Partial<Omit<ApiState, 'jobs'>> & {jobs?: MockJob[]} = {}
): Promise<ApiState> {
  const {jobs = [], ...rest} = initial;
  const state: ApiState = {
    session: false,
    unavailable: false,
    logoutStatus: 204,
    logins: [],
    jobs: {jobs, listQueries: [], writeStatus: 0, listStatus: 0, nextApplicationId: 100},
    ...rest
  };

  await page.route('https://fonts.googleapis.com/**', (route) =>
    route.fulfill({status: 200, contentType: 'text/css', body: ''})
  );

  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname.replace(/^\/api\/v1/, '') || '/';
    const key = `${request.method()} ${path}`;

    switch (key) {
      case 'GET /':
        return route.fulfill({json: success({service: 'jobscan-api', version: 'v1'})});

      case 'POST /auth/refresh':
        if (state.unavailable) {
          return route.fulfill({
            status: 503,
            json: failure('service_unavailable', 'Service momentanément indisponible.')
          });
        }

        return state.session
          ? route.fulfill({json: success(SESSION)})
          : route.fulfill({status: 401, json: failure('unauthorized', 'Authentification requise.')});

      case 'POST /auth/login': {
        const body = request.postDataJSON() as {username?: string; password?: string};
        state.logins.push(body);

        if (body.username === 'verrouille') {
          return route.fulfill({
            status: 429,
            headers: {'Retry-After': '60'},
            json: failure('too_many_requests', 'Trop de tentatives : réessayez dans une minute.')
          });
        }

        if (body.username === 'admin' && body.password === 'secret') {
          state.session = true;

          return route.fulfill({json: success(SESSION)});
        }

        return route.fulfill({status: 401, json: failure('invalid_credentials', 'Identifiants invalides.')});
      }

      case 'POST /auth/logout':
        if (state.logoutStatus === 204) {
          state.session = false;

          return route.fulfill({status: 204});
        }

        return route.fulfill({
          status: state.logoutStatus,
          json: failure('internal_error', 'Erreur interne du serveur.')
        });

      default:
        if (await handleJobs(state.jobs, route, request.method(), path, new URL(request.url()))) {
          return;
        }

        return route.fulfill({status: 404, json: failure('not_found', 'Ressource introuvable.')});
    }
  });

  return state;
}
