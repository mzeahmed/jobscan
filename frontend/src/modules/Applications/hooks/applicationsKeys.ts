import type {ApplicationStatusValue} from '@/modules/Applications/domain/application.ts';

export const applicationsKeys = {
  all: ['applications'] as const,
  lists: () => [...applicationsKeys.all, 'list'] as const,
  list: (status: ApplicationStatusValue | null) => [...applicationsKeys.lists(), status] as const,
  detail: (id: number) => [...applicationsKeys.all, 'detail', id] as const
};
