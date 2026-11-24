import {createContext} from 'react';
import type {AuthenticatedUser} from '@/api/symfonyAuth.ts';

export interface AuthContextValue {
  user: AuthenticatedUser | null;
  /** Jeton d'accès, gardé en mémoire seulement (jamais dans le stockage du navigateur). */
  token: string | null;
  /** La session est en cours de restauration au chargement de l'application. */
  loading: boolean;
  /** L'API ne répond pas (5xx, réseau, délai) : la session n'a pas pu être vérifiée. */
  unavailable: boolean;
  /** Cause technique de l'indisponibilité (ex. « TimeoutError », « HTTP 503 »), pour le diagnostic. */
  unavailableReason: string | null;
  /** Relance la restauration de la session (bouton « Réessayer »). */
  retrySession: () => void;
  /** Ouvre la session ; lève une `AuthenticationError` si l'API refuse. */
  login: (username: string, password: string) => Promise<void>;
  /** Ferme la session ; lève une erreur si le serveur ne l'a pas confirmé (la session reste alors ouverte). */
  logout: () => Promise<void>;
}

export const AuthContext = createContext<AuthContextValue | null>(null);
