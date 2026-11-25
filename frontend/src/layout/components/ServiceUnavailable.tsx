import {useTranslation} from 'react-i18next';
import {useAuth} from '@/auth/useAuth.ts';
import {Button} from '@/design-system/components/Button.tsx';

/** Réglages côté serveur nécessaires à la connexion (voir docs/UI.md) : des noms et des commandes, jamais de valeur. */
const SETTINGS = [
  {name: 'JOBSCAN_UI_PASSWORD_HASH', command: 'php bin/console app:ui:hash-password'},
  {name: 'JOBSCAN_API_JWT_SECRET', command: 'openssl rand -hex 32'}
] as const;

/**
 * Écran affiché quand la session ne peut pas être vérifiée au chargement : ce n'est pas une déconnexion, on propose de
 * réessayer. Un serveur qui répond « connexion non configurée » est dit comme tel : réessayer seul ne suffirait pas.
 */
export function ServiceUnavailable() {
  const {t} = useTranslation();
  const {retrySession, unavailableReason, notConfigured} = useAuth();

  return (
    <div className="js-centered">
      <div className="js-auth-card" role="alert">
        <div className="js-auth-brand">
          <h1 className="js-auth-name">
            {t(notConfigured ? 'layout.notConfigured.title' : 'layout.unavailable.title')}
          </h1>
          <p className="js-auth-tag">{t(notConfigured ? 'layout.notConfigured.text' : 'layout.unavailable.text')}</p>
        </div>
        {notConfigured && (
          <dl className="js-auth-settings">
            {SETTINGS.map((setting) => (
              <div key={setting.name}>
                <dt>
                  <code>{setting.name}</code>
                </dt>
                <dd>
                  <code>{setting.command}</code>
                </dd>
              </div>
            ))}
          </dl>
        )}
        <Button variant="primary" block onClick={retrySession}>
          {t('common.retry')}
        </Button>
        {!notConfigured && unavailableReason && (
          <p className="js-auth-reason">{t('layout.unavailable.reason', {reason: unavailableReason})}</p>
        )}
      </div>
    </div>
  );
}
