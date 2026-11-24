import {SYMFONY_API_URL} from '@/api/symfonyApiUrl.ts';
import i18n from '@/i18n/i18n.ts';

/**
 * Contrat d'authentification de l'API (lot API 1, voir docs/API.md) :
 * `POST /auth/login {username, password}`, `POST /auth/refresh`, `POST /auth/logout`. Le jeton d'accès est
 * renvoyé dans la réponse et gardé en mémoire ; le jeton de rafraîchissement est un cookie HttpOnly.
 */
export interface AuthenticatedUser {
  id: string;
  username: string;
  roles: string[];
}

export interface AuthSession {
  user: AuthenticatedUser;
  access_token: string;
  token_type: string;
  expires_in: number;
}

/** Refus de l'API d'authentification ; `status` = code HTTP de la réponse (0 : aucune réponse). */
export class AuthenticationError extends Error {
  readonly status: number;
  readonly code: string | null;

  constructor(message: string, status = 0, code: string | null = null) {
    super(message);
    this.name = 'AuthenticationError';
    this.status = status;
    this.code = code;
  }
}

/** Délai maximal de restauration de la session : au-delà, l'API est considérée indisponible. */
const REFRESH_TIMEOUT_MS = 15000;

/**
 * Vrai si l'échec ne vient pas d'un refus d'authentification : seuls 400, 401 et 403 signifient
 * « pas de session » (absente, expirée, révoquée). Tout le reste (5xx, 404 d'un proxy, 429, erreur réseau,
 * délai dépassé) est une indisponibilité : l'utilisateur ne doit pas être déconnecté pour autant.
 */
export function isAuthServiceUnavailable(error: unknown): boolean {
  if (!(error instanceof AuthenticationError)) {
    return true;
  }

  return ![400, 401, 403].includes(error.status);
}

interface AuthEnvelope {
  code: string;
  success: boolean;
  data: AuthSession & {message?: string};
}

export class SymfonyAuth {
  private pendingRefresh: Promise<AuthSession> | null = null;

  public login(username: string, password: string): Promise<AuthSession> {
    return this.request('/auth/login', {method: 'POST', body: JSON.stringify({username, password})});
  }

  public refresh(): Promise<AuthSession> {
    this.pendingRefresh ??= this.refreshWithCrossTabLock().finally(() => {
      this.pendingRefresh = null;
    });

    return this.pendingRefresh;
  }

  /** Révoque la session côté API. Une réponse d'erreur est levée : la session n'est pas fermée si le serveur n'a pas confirmé. */
  public async logout(): Promise<void> {
    const response = await fetch(`${SYMFONY_API_URL}/auth/logout`, {method: 'POST', credentials: 'include'});

    if (!response.ok) {
      throw new AuthenticationError(i18n.t('auth.logoutFailed'), response.status);
    }
  }

  private async request(path: string, init: RequestInit): Promise<AuthSession> {
    const response = await fetch(`${SYMFONY_API_URL}${path}`, {
      ...init,
      credentials: 'include',
      headers: {'Content-Type': 'application/json', ...init.headers}
    });

    const envelope = (await response.json().catch(() => null)) as AuthEnvelope | null;

    if (!response.ok || !envelope || !envelope.success) {
      throw new AuthenticationError(
        envelope?.data?.message ?? i18n.t('auth.authenticationFailed'),
        response.status,
        envelope?.code ?? null
      );
    }

    return envelope.data;
  }

  /**
   * Le jeton de rafraîchissement est rotatif et à usage unique. Ouvrir un second onglet peut provoquer deux
   * rafraîchissements simultanés avec le même cookie : le second invaliderait la session. Web Locks les
   * séquentialise entre les onglets de cette origine ; le cookie renouvelé par le premier sert au suivant.
   *
   * Après un délai dépassé, le serveur a pu consommer le cookie sans que le navigateur reçoive la réponse :
   * on ne rejoue jamais automatiquement la même requête.
   */
  private refreshWithCrossTabLock(): Promise<AuthSession> {
    const attempt = () =>
      this.request('/auth/refresh', {method: 'POST', body: '{}', signal: AbortSignal.timeout(REFRESH_TIMEOUT_MS)});

    if ('locks' in navigator) {
      return navigator.locks.request('jobscan-auth-refresh', attempt);
    }

    return attempt();
  }
}

export const symfonyAuth = new SymfonyAuth();
