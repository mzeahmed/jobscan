import type {AnalyticsWindow} from '@/modules/Analytics/domain/analytics.ts';

export const analyticsKeys = {
  all: ['analytics'] as const,
  report: (since: AnalyticsWindow) => [...analyticsKeys.all, 'report', since] as const
};
