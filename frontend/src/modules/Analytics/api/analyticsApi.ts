import {symfonyClient} from '@/api/symfonyClient.ts';
import type {AnalyticsReport, AnalyticsWindow} from '@/modules/Analytics/domain/analytics.ts';

export function getAnalytics(
  token: string | null,
  since: AnalyticsWindow,
  signal?: AbortSignal
): Promise<AnalyticsReport> {
  return symfonyClient.fetch('/analytics', token, {query: {since}, signal});
}
