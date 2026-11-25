import {keepPreviousData, useQuery} from '@tanstack/react-query';
import {useAuth} from '@/auth/useAuth.ts';
import {getApplication, listApplications} from '@/modules/Applications/api/applicationsApi.ts';
import type {ApplicationStatusValue} from '@/modules/Applications/domain/application.ts';
import {applicationsKeys} from '@/modules/Applications/hooks/applicationsKeys.ts';

export function useApplications(status: ApplicationStatusValue | null) {
  const {token} = useAuth();

  return useQuery({
    queryKey: applicationsKeys.list(status),
    queryFn: ({signal}) => listApplications(token, status, signal),
    enabled: token !== null,
    placeholderData: keepPreviousData
  });
}

export function useApplication(id: number) {
  const {token} = useAuth();

  return useQuery({
    queryKey: applicationsKeys.detail(id),
    queryFn: ({signal}) => getApplication(token, id, signal),
    enabled: token !== null
  });
}
