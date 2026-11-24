import {faBriefcase, faChartColumn, faHouse, faPaperPlane} from '@fortawesome/free-solid-svg-icons';
import type {IconSource} from '@/design-system/components/Icon.tsx';

export interface NavItem {
  to: string;
  /** Clé i18n du libellé. */
  labelKey: string;
  icon: IconSource;
  /** Actif seulement sur l'adresse exacte (accueil). */
  end?: boolean;
}

export const navigation: readonly NavItem[] = [
  {to: '/', labelKey: 'layout.nav.home', icon: faHouse, end: true},
  {to: '/jobs', labelKey: 'layout.nav.jobs', icon: faBriefcase},
  {to: '/applications', labelKey: 'layout.nav.applications', icon: faPaperPlane},
  {to: '/analytics', labelKey: 'layout.nav.analytics', icon: faChartColumn}
];
