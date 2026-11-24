import {Link} from 'react-router-dom';
import {useTranslation} from 'react-i18next';
import {faCompass} from '@fortawesome/free-regular-svg-icons';
import {EmptyState} from '@/design-system/components/EmptyState.tsx';

export function NotFoundPage() {
  const {t} = useTranslation();

  return (
    <EmptyState
      icon={faCompass}
      title={t('layout.notFound.title')}
      text={t('layout.notFound.text')}
      action={
        <Link className="js-btn js-btn-primary" to="/">
          {t('layout.notFound.back')}
        </Link>
      }
    />
  );
}
