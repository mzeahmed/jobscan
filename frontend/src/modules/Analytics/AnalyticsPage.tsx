import {useSearchParams} from 'react-router-dom';
import {useTranslation} from 'react-i18next';
import {faChartColumn} from '@fortawesome/free-solid-svg-icons';
import {Button} from '@/design-system/components/Button.tsx';
import {EmptyState} from '@/design-system/components/EmptyState.tsx';
import {InlineAlert} from '@/design-system/components/InlineAlert.tsx';
import {Skeleton} from '@/design-system/components/Skeleton.tsx';
import {BreakdownTable} from '@/modules/Analytics/components/BreakdownTable.tsx';
import {FunnelCard} from '@/modules/Analytics/components/FunnelCard.tsx';
import {SourcingTable} from '@/modules/Analytics/components/SourcingTable.tsx';
import {WindowTabs} from '@/modules/Analytics/components/WindowTabs.tsx';
import {dimensions, windowFromParams} from '@/modules/Analytics/domain/analytics.ts';
import {useAnalytics} from '@/modules/Analytics/hooks/useAnalytics.ts';
import {formatDateTime} from '@/i18n/format.ts';

export function AnalyticsPage() {
  const {t} = useTranslation();
  const [searchParams] = useSearchParams();
  const since = windowFromParams(searchParams);
  const {data, isPending, isError, error, refetch, isFetching, isPlaceholderData} = useAnalytics(since);

  return (
    <>
      <div className="js-page-header">
        <div className="js-page-eyebrow">{t('analytics.eyebrow')}</div>
        <h1 className="js-page-title">{t('analytics.title')}</h1>
        <p className="js-page-subtitle">{t('analytics.subtitle')}</p>
      </div>

      <WindowTabs active={since} />

      {isPending && (
        <div className="js-stack" role="status" aria-label={t('common.loading')}>
          <Skeleton shape="block" />
          <Skeleton shape="block" />
        </div>
      )}

      {isError && (
        <div className="js-stack">
          <InlineAlert tone="danger">{t('analytics.loadFailed', {message: error.message})}</InlineAlert>
          <div>
            <Button variant="primary" size="sm" disabled={isFetching} onClick={() => void refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        </div>
      )}

      {data && (
        <div className={`js-stack${isPlaceholderData ? ' js-job-list-stale' : ''}`} aria-busy={isPlaceholderData}>
          {data.funnel.sent === 0 ? (
            <EmptyState icon={faChartColumn} title={t('analytics.empty.title')} text={t('analytics.empty.text')} />
          ) : (
            <>
              <InlineAlert tone="info">{t('analytics.sample', {count: data.min_sample})}</InlineAlert>
              <FunnelCard funnel={data.funnel} />
              <div className="js-analytics-grid">
                {dimensions.map((dimension) => (
                  <BreakdownTable key={dimension} dimension={dimension} rows={data.breakdowns[dimension]} />
                ))}
              </div>
            </>
          )}
          <SourcingTable rows={data.sourcing} />
          <p className="js-form-hint">{t('analytics.generatedAt', {date: formatDateTime(data.generated_at)})}</p>
        </div>
      )}
    </>
  );
}
