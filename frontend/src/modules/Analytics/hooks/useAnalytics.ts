import {keepPreviousData, useQuery} from '@tanstack/react-query';
import {useAuth} from '@/auth/useAuth.ts';
import {getAnalytics} from '@/modules/Analytics/api/analyticsApi.ts';
import type {AnalyticsWindow} from '@/modules/Analytics/domain/analytics.ts';
import {analyticsKeys} from '@/modules/Analytics/hooks/analyticsKeys.ts';

export function useAnalytics(since: AnalyticsWindow) {
  const {token} = useAuth();

  return useQuery({
    queryKey: analyticsKeys.report(since),
    queryFn: ({signal}) => getAnalytics(token, since, signal),
    enabled: token !== null,
    placeholderData: keepPreviousData
  });
}
