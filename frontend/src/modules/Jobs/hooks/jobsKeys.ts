import type {JobFilters} from '@/modules/Jobs/domain/jobFilters.ts';

export const jobsKeys = {
  all: ['jobs'] as const,
  lists: () => [...jobsKeys.all, 'list'] as const,
  list: (filters: JobFilters) => [...jobsKeys.lists(), filters] as const
};
