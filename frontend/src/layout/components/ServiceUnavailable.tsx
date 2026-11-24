import {useTranslation} from 'react-i18next';
import {useAuth} from '@/auth/useAuth.ts';
import {Button} from '@/design-system/components/Button.tsx';

/** Écran affiché quand l'API ne répond pas au chargement : ce n'est pas une déconnexion, on propose de réessayer. */
export function ServiceUnavailable() {
  const {t} = useTranslation();
  const {retrySession, unavailableReason} = useAuth();

  return (
    <div className="js-centered">
      <div className="js-auth-card" role="alert">
        <div className="js-auth-brand">
          <h1 className="js-auth-name">{t('layout.unavailable.title')}</h1>
          <p className="js-auth-tag">{t('layout.unavailable.text')}</p>
        </div>
        <Button variant="primary" block onClick={retrySession}>
          {t('common.retry')}
        </Button>
        {unavailableReason && (
          <p className="js-auth-reason">{t('layout.unavailable.reason', {reason: unavailableReason})}</p>
        )}
      </div>
    </div>
  );
}
