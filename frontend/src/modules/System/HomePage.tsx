import {useTranslation} from 'react-i18next';
import {Badge} from '@/design-system/components/Badge.tsx';
import {Button} from '@/design-system/components/Button.tsx';
import {Card} from '@/design-system/components/Card.tsx';
import {InlineAlert} from '@/design-system/components/InlineAlert.tsx';
import {Skeleton} from '@/design-system/components/Skeleton.tsx';
import {useApiInfo} from '@/modules/System/hooks/useApiInfo.ts';

export function HomePage() {
  const {t} = useTranslation();
  const {data, isPending, isError, error, refetch, isFetching} = useApiInfo();

  return (
    <>
      <div className="js-page-header">
        <div className="js-page-eyebrow">{t('system.home.eyebrow')}</div>
        <h1 className="js-page-title">{t('system.home.title')}</h1>
      </div>

      <Card
        title={t('system.api.title')}
        actions={
          isPending ? (
            <Badge>{t('system.api.checking')}</Badge>
          ) : isError ? (
            <Badge tone="danger">{t('system.api.unavailable')}</Badge>
          ) : (
            <Badge tone="success">{t('system.api.connected')}</Badge>
          )
        }
      >
        {isPending && (
          <div className="js-stack" role="status" aria-label={t('common.loading')}>
            <Skeleton />
            <Skeleton shape="short" />
          </div>
        )}

        {isError && (
          <div className="js-stack">
            <InlineAlert tone="danger">{t('system.api.failed', {message: error.message})}</InlineAlert>
            <div>
              <Button variant="primary" size="sm" disabled={isFetching} onClick={() => void refetch()}>
                {t('common.retry')}
              </Button>
            </div>
          </div>
        )}

        {data && (
          <dl className="js-stack">
            <div>
              <dt className="js-field-label">{t('system.api.service')}</dt>
              <dd>{data.service}</dd>
            </div>
            <div>
              <dt className="js-field-label">{t('system.api.version')}</dt>
              <dd>{data.version}</dd>
            </div>
          </dl>
        )}
      </Card>
    </>
  );
}
