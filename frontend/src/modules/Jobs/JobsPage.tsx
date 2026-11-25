import {useMemo} from 'react';
import {useSearchParams} from 'react-router-dom';
import {useTranslation} from 'react-i18next';
import {faMagnifyingGlass} from '@fortawesome/free-solid-svg-icons';
import {Button} from '@/design-system/components/Button.tsx';
import {EmptyState} from '@/design-system/components/EmptyState.tsx';
import {InlineAlert} from '@/design-system/components/InlineAlert.tsx';
import {Pagination} from '@/design-system/components/Pagination.tsx';
import {Skeleton} from '@/design-system/components/Skeleton.tsx';
import {JobCard} from '@/modules/Jobs/components/JobCard.tsx';
import {JobFiltersBar} from '@/modules/Jobs/components/JobFiltersBar.tsx';
import {activeFilterCount, filtersToParams, type JobFilters, parseFilters} from '@/modules/Jobs/domain/jobFilters.ts';
import {useDecideJob, useTrackJob} from '@/modules/Jobs/hooks/useJobActions.ts';
import {useJobs} from '@/modules/Jobs/hooks/useJobs.ts';

export function JobsPage() {
  const {t} = useTranslation();
  const [searchParams, setSearchParams] = useSearchParams();
  const filters = useMemo(() => parseFilters(searchParams), [searchParams]);
  const {data, isPending, isError, error, refetch, isFetching, isPlaceholderData} = useJobs(filters);
  const decide = useDecideJob();
  const track = useTrackJob();

  const actionError = decide.error ?? track.error;
  const busyJobId = decide.isPending ? decide.variables.jobId : track.isPending ? track.variables : null;
  const filtered = activeFilterCount(filters) > 0;

  function apply(next: JobFilters) {
    setSearchParams(filtersToParams(next));
  }

  return (
    <>
      <div className="js-page-header">
        <div className="js-page-eyebrow">{t('jobs.eyebrow')}</div>
        <h1 className="js-page-title">{t('jobs.title')}</h1>
        {data && (
          <p className="js-page-subtitle" aria-live="polite">
            {t(filtered ? 'jobs.countFiltered' : 'jobs.count', {count: data.pagination.total})}
          </p>
        )}
      </div>

      <JobFiltersBar filters={filters} sources={data?.sources ?? []} onApply={apply} />

      {actionError && (
        <InlineAlert tone="danger" className="js-jobs-alert">
          {t('jobs.actionFailed', {message: actionError.message})}
        </InlineAlert>
      )}

      {isPending && (
        <div className="js-stack" role="status" aria-label={t('common.loading')}>
          <Skeleton shape="block" />
          <Skeleton shape="block" />
          <Skeleton shape="block" />
        </div>
      )}

      {isError && (
        <div className="js-stack">
          <InlineAlert tone="danger">{t('jobs.loadFailed', {message: error.message})}</InlineAlert>
          <div>
            <Button variant="primary" size="sm" disabled={isFetching} onClick={() => void refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        </div>
      )}

      {data && data.items.length === 0 && (
        <EmptyState
          icon={faMagnifyingGlass}
          title={t('jobs.empty.title')}
          text={t(filtered ? 'jobs.empty.filtered' : 'jobs.empty.none')}
        />
      )}

      {data && data.items.length > 0 && (
        <div aria-busy={isPlaceholderData}>
          <ul className={`js-job-list${isPlaceholderData ? ' js-job-list-stale' : ''}`}>
            {data.items.map((job) => (
              <JobCard
                key={job.id}
                job={job}
                busy={busyJobId === job.id}
                tracking={track.isPending && track.variables === job.id}
                onTrack={(jobId) => track.mutate(jobId)}
                onDecide={(jobId, state) => decide.mutate({jobId, state})}
              />
            ))}
          </ul>
          <Pagination
            page={data.pagination.page}
            totalPages={data.pagination.total_pages}
            onChange={(page) => apply({...filters, page})}
          />
        </div>
      )}
    </>
  );
}
