import {Link} from 'react-router-dom';
import {useTranslation} from 'react-i18next';
import type {ApplicationStatusValue, StatusCount} from '@/modules/Applications/domain/application.ts';

interface StatusTabsProps {
  counts: readonly StatusCount[];
  total: number;
  active: ApplicationStatusValue | null;
}

/** Onglets par statut (liens : l'adresse porte le filtre). Un statut vide n'est affiché que s'il est actif. */
export function StatusTabs({counts, total, active}: StatusTabsProps) {
  const {t} = useTranslation();
  const tabs = counts.filter((entry) => entry.count > 0 || entry.value === active);

  return (
    <nav className="js-tabs" aria-label={t('applications.tabs.label')}>
      <Link className="js-tab" to="/applications" aria-current={active === null ? 'page' : undefined}>
        {t('applications.tabs.all')} <span className="js-tab-count">{total}</span>
      </Link>
      {tabs.map((entry) => (
        <Link
          key={entry.value}
          className="js-tab"
          to={`/applications?status=${entry.value}`}
          aria-current={active === entry.value ? 'page' : undefined}
        >
          {entry.label} <span className="js-tab-count">{entry.count}</span>
        </Link>
      ))}
    </nav>
  );
}
