import {type ReactNode, useCallback, useEffect, useMemo, useState} from 'react';
import {AuthenticationError, type AuthSession, isAuthServiceUnavailable, symfonyAuth} from '@/api/symfonyAuth.ts';
import {symfonyClient} from '@/api/symfonyClient.ts';
import {AuthContext, type AuthContextValue} from '@/auth/AuthContext.ts';
import {queryClient} from '@/queries/queryClient.ts';

/** Délai avant le second essai : laisse passer une coupure brève ou un redémarrage de l'API. */
const RESTORE_RETRY_DELAY_MS = 2500;

export function AuthProvider({children}: {children: ReactNode}) {
  const [auth, setAuth] = useState<AuthSession | null>(null);
  const [loading, setLoading] = useState(true);
  const [unavailable, setUnavailable] = useState(false);
  const [unavailableReason, setUnavailableReason] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);

  // Restauration de la session : une API indisponible n'est pas une déconnexion.
  useEffect(() => {
    let cancelled = false;

    void restoreSession()
      .then((session) => {
        if (!cancelled) {
          setAuth(session);
          setUnavailable(false);
          setUnavailableReason(null);
        }
      })
      .catch((error: unknown) => {
        if (cancelled) {
          return;
        }

        if (isAuthServiceUnavailable(error)) {
          setUnavailable(true);
          setUnavailableReason(describeUnavailability(error));

          return;
        }

        setAuth(null);
        setUnavailable(false);
        setUnavailableReason(null);
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [attempt]);

  const retrySession = useCallback(() => {
    setLoading(true);
    setAttempt((current) => current + 1);
  }, []);

  // Renouvellement du jeton d'accès sur un 401 (voir SymfonyClient).
  useEffect(() => {
    symfonyClient.setAccessTokenRefresher(async () => {
      try {
        const session = await symfonyAuth.refresh();
        setAuth(session);

        return session.access_token;
      } catch (error) {
        // API indisponible : la requête échoue (message de la page), la session est gardée.
        if (!isAuthServiceUnavailable(error)) {
          setAuth(null);
        }

        return null;
      }
    });

    return () => symfonyClient.setAccessTokenRefresher(null);
  }, []);

  const login = useCallback(async (username: string, password: string) => {
    setAuth(await symfonyAuth.login(username, password));
  }, []);

  const logout = useCallback(async () => {
    await symfonyAuth.logout();
    // Rien de l'ancienne session ne doit rester en cache pour le prochain utilisateur de l'appareil.
    queryClient.clear();
    setAuth(null);
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      user: auth?.user ?? null,
      token: auth?.access_token ?? null,
      loading,
      unavailable,
      unavailableReason,
      retrySession,
      login,
      logout
    }),
    [auth, loading, unavailable, unavailableReason, retrySession, login, logout]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

/**
 * Restauration de la session au chargement, avec un second essai si la requête n'a reçu aucune réponse lisible
 * (`TypeError` : réseau coupé, API en redémarrage). Pas de second essai après un délai dépassé
 * (`TimeoutError`) : le jeton de rafraîchissement a pu être consommé, on ne le rejoue pas.
 */
async function restoreSession(): Promise<AuthSession> {
  try {
    return await symfonyAuth.refresh();
  } catch (error) {
    if (!(error instanceof TypeError)) {
      throw error;
    }

    await new Promise((resolve) => setTimeout(resolve, RESTORE_RETRY_DELAY_MS));

    return symfonyAuth.refresh();
  }
}

/** Code court affiché sur l'écran d'indisponibilité : « TypeError », « TimeoutError » ou « HTTP 503 ». Jamais de jeton. */
function describeUnavailability(error: unknown): string {
  if (error instanceof AuthenticationError) {
    return `HTTP ${error.status}`;
  }

  return error instanceof Error ? error.name : 'inconnue';
}
