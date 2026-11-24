import {useQuery} from '@tanstack/react-query';
import {getApiInfo} from '@/modules/System/api/systemApi.ts';
import {systemKeys} from '@/modules/System/hooks/systemKeys.ts';

export function useApiInfo() {
  return useQuery({
    queryKey: systemKeys.apiInfo(),
    queryFn: ({signal}) => getApiInfo(signal)
  });
}
