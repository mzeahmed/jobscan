import {useMutation, useQueryClient} from '@tanstack/react-query';
import {useAuth} from '@/auth/useAuth.ts';
import {changeStatus, updateApplication} from '@/modules/Applications/api/applicationsApi.ts';
import type {
  ApplicationDetail,
  ApplicationDetailResponse,
  ApplicationPatch,
  StatusChange
} from '@/modules/Applications/domain/application.ts';
import {applicationsKeys} from '@/modules/Applications/hooks/applicationsKeys.ts';
import {jobsKeys} from '@/modules/Jobs/hooks/jobsKeys.ts';

/**
 * Après une écriture : le détail affiche aussitôt la candidature renvoyée par l'API (les choix des formulaires, eux,
 * ne changent pas), et les listes (candidatures, offres) sont rechargées car statut, compteurs et liens ont pu bouger.
 */
function useRefreshAfterWrite(id: number) {
  const queryClient = useQueryClient();

  return (result: {application: ApplicationDetail}) => {
    queryClient.setQueryData<ApplicationDetailResponse>(applicationsKeys.detail(id), (current) =>
      current ? {...current, application: result.application} : current
    );

    return Promise.all([
      queryClient.invalidateQueries({queryKey: applicationsKeys.lists()}),
      queryClient.invalidateQueries({queryKey: jobsKeys.lists()})
    ]);
  };
}

export function useChangeStatus(id: number) {
  const {token} = useAuth();
  const refresh = useRefreshAfterWrite(id);

  return useMutation({
    mutationFn: (change: StatusChange) => changeStatus(token, id, change),
    onSuccess: refresh
  });
}

export function useUpdateApplication(id: number) {
  const {token} = useAuth();
  const refresh = useRefreshAfterWrite(id);

  return useMutation({
    mutationFn: (patch: ApplicationPatch) => updateApplication(token, id, patch),
    onSuccess: refresh
  });
}
