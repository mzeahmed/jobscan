import {useTranslation} from 'react-i18next';
import {Skeleton} from '@/design-system/components/Skeleton.tsx';

/** Écran de chargement pendant la restauration de la session. */
export function FullPageLoader() {
  const {t} = useTranslation();

  return (
    <div className="js-centered" role="status" aria-label={t('common.loading')}>
      <div className="js-auth-card">
        <Skeleton shape="short" />
        <Skeleton shape="block" />
      </div>
    </div>
  );
}
