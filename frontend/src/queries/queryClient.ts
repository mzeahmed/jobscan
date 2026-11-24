import {QueryClient} from '@tanstack/react-query';
import {SymfonyApiError} from '@/api/symfonyClient.ts';

/**
 * Un 4xx (401, 403, 404, 422…) ne se résoudra jamais en réessayant : seules les pannes réseau et les 5xx valent
 * un nouvel essai. Sans ce filtre, les trois tentatives par défaut de TanStack Query retarderaient l'affichage
 * d'une erreur « introuvable ».
 */
function shouldRetryQuery(failureCount: number, error: unknown): boolean {
  if (error instanceof SymfonyApiError && error.status < 500) {
    return false;
  }

  return failureCount < 2;
}

/**
 * Instance unique du QueryClient. Choix délibérés :
 *
 * - `staleTime: 30s` : outil personnel, pas de flux temps réel ; évite un rechargement à chaque changement de
 *   page tout en gardant des données assez fraîches ;
 * - `gcTime: 5 min` : valeur par défaut, fixée explicitement pour rester un choix documenté ;
 * - `retry` (lectures) : voir `shouldRetryQuery` ;
 * - `retry: false` (écritures) : une mutation (POST, PATCH, DELETE) n'est pas forcément idempotente ; la rejouer
 *   automatiquement risquerait une double écriture. Le nouvel essai, quand il a du sens, est un bouton ;
 * - `refetchOnWindowFocus: true` : revenir sur l'onglet doit montrer des données à jour.
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      gcTime: 5 * 60_000,
      retry: shouldRetryQuery,
      refetchOnWindowFocus: true
    },
    mutations: {
      retry: false
    }
  }
});
