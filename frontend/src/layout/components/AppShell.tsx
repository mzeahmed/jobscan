import {useState} from 'react';
import {NavLink, Outlet} from 'react-router-dom';
import {useTranslation} from 'react-i18next';
import {faRightFromBracket} from '@fortawesome/free-solid-svg-icons';
import {useAuth} from '@/auth/useAuth.ts';
import {Button} from '@/design-system/components/Button.tsx';
import {Icon} from '@/design-system/components/Icon.tsx';
import {InlineAlert} from '@/design-system/components/InlineAlert.tsx';
import {navigation} from '@/layout/config/navigation.ts';

/** Coque de l'application : navigation, utilisateur connecté, déconnexion, zone de contenu. */
export function AppShell() {
  const {t} = useTranslation();
  const {user, logout} = useAuth();
  const [loggingOut, setLoggingOut] = useState(false);
  const [logoutFailed, setLogoutFailed] = useState(false);

  async function handleLogout() {
    setLoggingOut(true);
    setLogoutFailed(false);

    try {
      await logout();
    } catch {
      // Le serveur n'a pas confirmé : la session reste ouverte, et l'utilisateur en est informé.
      setLogoutFailed(true);
      setLoggingOut(false);
    }
  }

  return (
    <div className="js-shell">
      <a className="js-skip-link" href="#contenu">
        {t('layout.skipToContent')}
      </a>

      <aside className="js-sidebar">
        <NavLink to="/" className="js-brand">
          <img src="/favicon.png" alt="" />
          <span>JOBSCAN</span>
        </NavLink>

        <nav className="js-nav" aria-label={t('layout.nav.label')}>
          <div className="js-nav-label" aria-hidden="true">
            {t('layout.nav.section')}
          </div>
          {navigation.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className="js-nav-link">
              <Icon icon={item.icon} />
              {t(item.labelKey)}
            </NavLink>
          ))}
        </nav>
      </aside>

      <div className="js-main">
        <header className="js-topbar">
          {user && <span className="js-topbar-user">{t('layout.signedInAs', {username: user.username})}</span>}
          <Button size="sm" variant="ghost" icon={faRightFromBracket} disabled={loggingOut} onClick={handleLogout}>
            {loggingOut ? t('layout.loggingOut') : t('layout.logout')}
          </Button>
        </header>

        <main id="contenu" className="js-content" tabIndex={-1}>
          {logoutFailed && (
            <InlineAlert tone="danger" className="js-page-header">
              {t('layout.logoutFailed')}
            </InlineAlert>
          )}
          <Outlet />
        </main>
      </div>
    </div>
  );
}
