import {Link, useSearchParams} from 'react-router-dom';
import {useTranslation} from 'react-i18next';
import {faPaperPlane} from '@fortawesome/free-regular-svg-icons';
import {Button} from '@/design-system/components/Button.tsx';
import {EmptyState} from '@/design-system/components/EmptyState.tsx';
import {InlineAlert} from '@/design-system/components/InlineAlert.tsx';
import {Skeleton} from '@/design-system/components/Skeleton.tsx';
import {ApplicationCard} from '@/modules/Applications/components/ApplicationCard.tsx';
import {StatusTabs} from '@/modules/Applications/components/StatusTabs.tsx';
import type {ApplicationStatusValue} from '@/modules/Applications/domain/application.ts';
import {useApplications} from '@/modules/Applications/hooks/useApplications.ts';

const STATUSES: readonly ApplicationStatusValue[] = [
  'draft',
  'applied',
  'interview',
  'technical_test',
  'offer',
  'accepted',
  'rejected',
  'withdrawn',
  'no_response'
];

/** Le statut demandé dans l'adresse, ou `null` : une valeur inconnue est ignorée (jamais envoyée à l'API). */
function statusFromParams(params: URLSearchParams): ApplicationStatusValue | null {
  return STATUSES.find((status) => status === params.get('status')) ?? null;
}

export function ApplicationsPage() {
  const {t} = useTranslation();
  const [searchParams] = useSearchParams();
  const status = statusFromParams(searchParams);
  const {data, isPending, isError, error, refetch, isFetching, isPlaceholderData} = useApplications(status);

  return (
    <>
      <div className="js-page-header">
        <div className="js-page-eyebrow">{t('applications.eyebrow')}</div>
        <h1 className="js-page-title">{t('applications.title')}</h1>
        {data && (
          <p className="js-page-subtitle" aria-live="polite">
            {t('applications.subtitle', {count: data.total})}
          </p>
        )}
      </div>

      {data && <StatusTabs counts={data.counts} total={data.total} active={status} />}

      {data && data.due_follow_ups > 0 && (
        <InlineAlert tone="warning" className="js-applications-alert">
          {t('applications.dueFollowUps', {count: data.due_follow_ups})}
        </InlineAlert>
      )}

      {isPending && (
        <div className="js-stack" role="status" aria-label={t('common.loading')}>
          <Skeleton shape="block" />
          <Skeleton shape="block" />
        </div>
      )}

      {isError && (
        <div className="js-stack">
          <InlineAlert tone="danger">{t('applications.loadFailed', {message: error.message})}</InlineAlert>
          <div>
            <Button variant="primary" size="sm" disabled={isFetching} onClick={() => void refetch()}>
              {t('common.retry')}
            </Button>
          </div>
        </div>
      )}

      {data && data.items.length === 0 && (
        <EmptyState
          icon={faPaperPlane}
          title={t('applications.empty.title')}
          text={t(status === null ? 'applications.empty.none' : 'applications.empty.filtered')}
          action={
            status === null ? (
              <Link className="js-btn js-btn-primary" to="/jobs">
                {t('applications.empty.goToJobs')}
              </Link>
            ) : undefined
          }
        />
      )}

      {data && data.items.length > 0 && (
        <div aria-busy={isPlaceholderData}>
          <ul className={`js-application-list${isPlaceholderData ? ' js-job-list-stale' : ''}`}>
            {data.items.map((application) => (
              <ApplicationCard key={application.id} application={application} />
            ))}
          </ul>
          {data.total > data.limit && status === null && (
            <p className="js-form-hint">{t('applications.limit', {limit: data.limit})}</p>
          )}
        </div>
      )}
    </>
  );
}
