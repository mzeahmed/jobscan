import i18n from '@/i18n/i18n.ts';
import {SYMFONY_API_URL} from './symfonyApiUrl.ts';

/** Erreur renvoyée par l'API : statut HTTP, code d'erreur stable de l'enveloppe et champs complémentaires. */
export class SymfonyApiError extends Error {
  readonly status: number;
  readonly code: string | null;
  /** Champs complémentaires de l'erreur (ex. `fields`, `retry_after`). */
  readonly details: Record<string, unknown>;

  constructor(status: number, message: string, code: string | null = null, details: Record<string, unknown> = {}) {
    super(message);
    this.name = 'SymfonyApiError';
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

type QueryValue = string | number | boolean | null | undefined;

/** Enveloppe commune de l'API : `{code, success, data:{message, …}}` (voir docs/API.md). */
interface ApiEnvelope<T> {
  code: string;
  success: boolean;
  data: T & {message?: string};
}

export interface SymfonyFetchOptions extends RequestInit {
  query?: Record<string, QueryValue>;
  retryAfterRefresh?: boolean;
}

type AccessTokenRefresher = () => Promise<string | null>;

export class SymfonyClient {
  private accessTokenRefresher: AccessTokenRefresher | null = null;

  private inFlightRefresh: Promise<string | null> | null = null;

  public setAccessTokenRefresher(refresher: AccessTokenRefresher | null): void {
    this.accessTokenRefresher = refresher;
  }

  /**
   * Appelle l'API et rend le contenu de `data` de l'enveloppe, ou lève une `SymfonyApiError`.
   *
   * Sur un 401 avec jeton, le jeton d'accès est renouvelé une fois (cookie de rafraîchissement) puis la requête
   * est rejouée. Un seul renouvellement est en vol à la fois : le jeton de rafraîchissement est à usage unique
   * côté API, donc deux 401 simultanés qui lanceraient chacun leur renouvellement feraient échouer le second.
   */
  public async fetch<T>(
    path: string,
    token: string | null,
    {query, retryAfterRefresh = true, ...init}: SymfonyFetchOptions = {}
  ): Promise<T> {
    const params = new URLSearchParams();
    Object.entries(query ?? {}).forEach(([key, value]) => {
      if (value !== null && value !== undefined) {
        params.set(key, String(value));
      }
    });
    const queryString = params.toString();
    const url = `${SYMFONY_API_URL}${path}${queryString ? `?${queryString}` : ''}`;

    const response = await fetch(url, {
      ...init,
      // Le cookie de rafraîchissement est HttpOnly : le navigateur l'envoie seul, jamais le code JavaScript.
      credentials: 'include',
      headers: {
        ...(typeof init.body === 'string' ? {'Content-Type': 'application/json'} : {}),
        ...init.headers,
        ...(token ? {Authorization: `Bearer ${token}`} : {})
      }
    });

    if (response.status === 401 && token && retryAfterRefresh && this.accessTokenRefresher) {
      const refreshedToken = await this.refreshAccessToken();
      if (refreshedToken) {
        return this.fetch<T>(path, refreshedToken, {query, ...init, retryAfterRefresh: false});
      }
    }

    if (response.status === 204) {
      return undefined as T;
    }

    const envelope = (await response.json().catch(() => null)) as ApiEnvelope<T> | null;
    if (!response.ok || !envelope || !envelope.success) {
      throw new SymfonyApiError(
        response.status,
        envelope?.data?.message ?? i18n.t('errors.apiStatus', {status: response.status}),
        envelope?.code ?? null,
        (envelope?.data ?? {}) as Record<string, unknown>
      );
    }

    return envelope.data;
  }

  private refreshAccessToken(): Promise<string | null> {
    if (!this.accessTokenRefresher) {
      return Promise.resolve(null);
    }

    if (!this.inFlightRefresh) {
      this.inFlightRefresh = this.accessTokenRefresher().finally(() => {
        this.inFlightRefresh = null;
      });
    }

    return this.inFlightRefresh;
  }
}

export const symfonyClient = new SymfonyClient();
