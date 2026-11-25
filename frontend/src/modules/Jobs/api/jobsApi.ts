import {symfonyClient} from '@/api/symfonyClient.ts';
import {filtersToParams, type JobFilters} from '@/modules/Jobs/domain/jobFilters.ts';
import type {JobStateValue, JobSummary, JobsPage} from '@/modules/Jobs/domain/job.ts';

export function listJobs(token: string | null, filters: JobFilters, signal?: AbortSignal): Promise<JobsPage> {
  return symfonyClient.fetch('/jobs', token, {query: Object.fromEntries(filtersToParams(filters)), signal});
}

/** Ouvre le suivi de l'offre (brouillon : rien n'est envoyé). Idempotent côté API. */
export function trackJob(token: string | null, jobId: number): Promise<{created: boolean; message: string}> {
  return symfonyClient.fetch(`/jobs/${jobId}/application`, token, {method: 'POST'});
}

/** Pose (`saved`, `ignored`, `archived`) ou retire (`null`) la décision de l'utilisateur sur l'offre. */
export function decideJob(
  token: string | null,
  jobId: number,
  state: JobStateValue | null
): Promise<{job: JobSummary}> {
  return symfonyClient.fetch(`/jobs/${jobId}/decision`, token, {method: 'PUT', body: JSON.stringify({state})});
}
