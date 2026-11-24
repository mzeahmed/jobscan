import {useTranslation} from 'react-i18next';
import {faHourglassHalf} from '@fortawesome/free-regular-svg-icons';
import {EmptyState} from '@/design-system/components/EmptyState.tsx';

/** Emplacement d'un écran pas encore migré depuis l'ancienne interface. */
export function ModuleNotBuiltPage() {
  const {t} = useTranslation();

  return <EmptyState icon={faHourglassHalf} title={t('layout.notBuilt.title')} text={t('layout.notBuilt.text')} />;
}
