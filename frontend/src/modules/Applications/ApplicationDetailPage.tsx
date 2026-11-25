import {Link, useParams} from 'react-router-dom';
import {useTranslation} from 'react-i18next';
import {faCircleQuestion} from '@fortawesome/free-regular-svg-icons';
import {faArrowLeft, faArrowUpRightFromSquare} from '@fortawesome/free-solid-svg-icons';
import {SymfonyApiError} from '@/api/symfonyClient.ts';
import {Button} from '@/design-system/components/Button.tsx';
import {Card} from '@/design-system/components/Card.tsx';
import {EmptyState} from '@/design-system/components/EmptyState.tsx';
import {Icon} from '@/design-system/components/Icon.tsx';
import {InlineAlert} from '@/design-system/components/InlineAlert.tsx';
import {Skeleton} from '@/design-system/components/Skeleton.tsx';
import {ApplicationDetailsForm} from '@/modules/Applications/components/ApplicationDetailsForm.tsx';
import {HistoryTimeline} from '@/modules/Applications/components/HistoryTimeline.tsx';
import {StatusBadge} from '@/modules/Applications/components/StatusBadge.tsx';
import {StatusChangeForm} from '@/modules/Applications/components/StatusChangeForm.tsx';
import {useApplication} from '@/modules/Applications/hooks/useApplications.ts';

export function ApplicationDetailPage() {
  const {t} = useTranslation();
  const params = useParams();
  // Un identifiant qui n'est pas un entier positif n'appelle jamais l'API : même écran qu'une candidature inconnue.
  const id = /^\d{1,18}$/.test(params.id ?? '') ? Number(params.id) : 0;
  const {data, isPending, isError, error, refetch, isFetching} = useApplication(id);

  const back = (
    <Link className="js-btn js-btn-ghost js-btn-sm" to="/applications">
      <Icon icon={faArrowLeft} />
      {t('applications.detail.back')}
    </Link>
  );

  if (id === 0 || (isError && error instanceof SymfonyApiError && error.status === 404)) {
    return (
      <EmptyState
        icon={faCircleQuestion}
        title={t('applications.detail.notFoundTitle')}
        text={t('applications.detail.notFoundText')}
        action={back}
      />
    );
  }

  if (isPending) {
    return (
      <div className="js-stack" role="status" aria-label={t('common.loading')}>
        <Skeleton shape="short" />
        <Skeleton shape="block" />
        <Skeleton shape="block" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="js-stack">
        <InlineAlert tone="danger">{t('applications.detail.loadFailed', {message: error.message})}</InlineAlert>
        <div>
          <Button variant="primary" size="sm" disabled={isFetching} onClick={() => void refetch()}>
            {t('common.retry')}
          </Button>
        </div>
      </div>
    );
  }

  const {application, options} = data;
  const {job} = application;
  const where = [job.company?.trim() || t('applications.card.unknownCompany'), job.location?.trim()].filter(Boolean);

  return (
    <>
      <div className="js-page-header">
        <div className="js-detail-actions">
          {back}
          {job.url && (
            <a
              className="js-btn js-btn-primary js-btn-sm"
              href={job.url}
              target="_blank"
              rel="noopener noreferrer nofollow"
            >
              {t('applications.detail.openOffer')}
              <Icon icon={faArrowUpRightFromSquare} />
            </a>
          )}
        </div>
        <h1 className="js-page-title">{job.title?.trim() || t('applications.card.untitled')}</h1>
        <p className="js-page-subtitle js-detail-subtitle">
          <StatusBadge status={application.status} />
          <span>{where.join(' · ')}</span>
        </p>
      </div>

      <div className="js-detail-layout">
        <div className="js-stack">
          <Card title={t('applications.detail.statusTitle')}>
            <StatusChangeForm application={application} />
          </Card>
          <Card title={t('applications.detail.detailsTitle')}>
            <ApplicationDetailsForm application={application} options={options} />
          </Card>
        </div>

        <Card
          title={t('applications.detail.historyTitle')}
          actions={
            <span className="js-form-hint">
              {t('applications.detail.historyCount', {count: application.history.length})}
            </span>
          }
        >
          <HistoryTimeline history={application.history} />
        </Card>
      </div>
    </>
  );
}
