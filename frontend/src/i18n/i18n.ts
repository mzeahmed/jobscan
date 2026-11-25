import i18n from 'i18next';
import {initReactI18next} from 'react-i18next';
import {fr as coreFr} from '@/i18n/locales/fr.ts';
import {fr as authFr} from '@/modules/Auth/i18n/fr.ts';
import {fr as layoutFr} from '@/layout/i18n/fr.ts';
import {fr as systemFr} from '@/modules/System/i18n/fr.ts';
import {fr as jobsFr} from '@/modules/Jobs/i18n/fr.ts';
import {fr as analyticsFr} from '@/modules/Analytics/i18n/fr.ts';
import {fr as applicationsFr} from '@/modules/Applications/i18n/fr.ts';

/** Français seul pour l'instant ; ajouter une langue = un fichier `en.ts` par module et une entrée ici. */
export const supportedLocales = ['fr'] as const;
export type Locale = (typeof supportedLocales)[number];

const resources = {
  fr: {
    translation: {
      ...coreFr,
      ...authFr,
      ...layoutFr,
      ...systemFr,
      ...jobsFr,
      ...applicationsFr,
      ...analyticsFr
    }
  }
} as const;

void i18n.use(initReactI18next).init({
  resources,
  lng: 'fr',
  fallbackLng: 'fr',
  supportedLngs: supportedLocales,
  interpolation: {escapeValue: false}
});

export default i18n;
