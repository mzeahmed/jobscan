import {SymfonyApiError} from '@/api/symfonyClient.ts';

/**
 * Messages par champ d'une réponse `422` (`data.errors`, voir docs/API.md). Vide pour toute autre erreur : seul le
 * message général s'affiche alors.
 */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof SymfonyApiError)) {
    return {};
  }

  const errors = error.details.errors;

  if (typeof errors !== 'object' || errors === null) {
    return {};
  }

  return Object.fromEntries(
    Object.entries(errors).filter((entry): entry is [string, string] => typeof entry[1] === 'string')
  );
}
