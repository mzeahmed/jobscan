import {symfonyClient} from '@/api/symfonyClient.ts';
import type {
  ApplicationDetail,
  ApplicationDetailResponse,
  ApplicationPatch,
  ApplicationsList,
  ApplicationStatusValue,
  StatusChange
} from '@/modules/Applications/domain/application.ts';

export function listApplications(
  token: string | null,
  status: ApplicationStatusValue | null,
  signal?: AbortSignal
): Promise<ApplicationsList> {
  return symfonyClient.fetch('/applications', token, {query: {status}, signal});
}

export function getApplication(
  token: string | null,
  id: number,
  signal?: AbortSignal
): Promise<ApplicationDetailResponse> {
  return symfonyClient.fetch(`/applications/${id}`, token, {signal});
}

/** Consigne un changement de statut déclaré par l'utilisateur : rien n'est envoyé à un recruteur. */
export function changeStatus(
  token: string | null,
  id: number,
  change: StatusChange
): Promise<{message: string; application: ApplicationDetail}> {
  return symfonyClient.fetch(`/applications/${id}/status`, token, {method: 'POST', body: JSON.stringify(change)});
}

export function updateApplication(
  token: string | null,
  id: number,
  patch: ApplicationPatch
): Promise<{message: string; application: ApplicationDetail}> {
  return symfonyClient.fetch(`/applications/${id}`, token, {method: 'PATCH', body: JSON.stringify(patch)});
}
