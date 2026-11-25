import {useMutation, useQueryClient} from '@tanstack/react-query';
import {useAuth} from '@/auth/useAuth.ts';
import {decideJob, trackJob} from '@/modules/Jobs/api/jobsApi.ts';
import type {JobStateValue} from '@/modules/Jobs/domain/job.ts';
import {jobsKeys} from '@/modules/Jobs/hooks/jobsKeys.ts';

/** Pose ou retire la décision sur une offre, puis recharge les listes (l'offre quitte la vue « à trier »). */
export function useDecideJob() {
  const {token} = useAuth();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({jobId, state}: {jobId: number; state: JobStateValue | null}) => decideJob(token, jobId, state),
    onSuccess: () => queryClient.invalidateQueries({queryKey: jobsKeys.lists()})
  });
}

/** Ouvre le suivi d'une offre (brouillon), puis recharge les listes pour afficher le lien vers la candidature. */
export function useTrackJob() {
  const {token} = useAuth();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (jobId: number) => trackJob(token, jobId),
    onSuccess: () => queryClient.invalidateQueries({queryKey: jobsKeys.lists()})
  });
}
