import {keepPreviousData, useQuery} from '@tanstack/react-query';
import {useAuth} from '@/auth/useAuth.ts';
import {listJobs} from '@/modules/Jobs/api/jobsApi.ts';
import type {JobFilters} from '@/modules/Jobs/domain/jobFilters.ts';
import {jobsKeys} from '@/modules/Jobs/hooks/jobsKeys.ts';

export function useJobs(filters: JobFilters) {
  const {token} = useAuth();

  return useQuery({
    queryKey: jobsKeys.list(filters),
    queryFn: ({signal}) => listJobs(token, filters, signal),
    enabled: token !== null,
    // Pendant un changement de page ou de filtre, la liste précédente reste affichée (grisée) au lieu de clignoter.
    placeholderData: keepPreviousData
  });
}
