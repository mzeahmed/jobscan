import {Link} from 'react-router-dom';
import {useTranslation} from 'react-i18next';
import {type AnalyticsWindow, defaultWindow, windows} from '@/modules/Analytics/domain/analytics.ts';

interface WindowTabsProps {
  active: AnalyticsWindow;
}

/** Choix de la période (liens : l'adresse porte le choix). La période par défaut n'ajoute rien à l'adresse. */
export function WindowTabs({active}: WindowTabsProps) {
  const {t} = useTranslation();

  return (
    <nav className="js-tabs" aria-label={t('analytics.windows.label')}>
      {windows.map((value) => (
        <Link
          key={value}
          className="js-tab"
          to={value === defaultWindow ? '/analytics' : `/analytics?since=${value}`}
          aria-current={active === value ? 'page' : undefined}
        >
          {t(`analytics.windows.${value}`)}
        </Link>
      ))}
    </nav>
  );
}
