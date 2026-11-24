import {type SubmitEvent, useEffect, useRef, useState} from 'react';
import {useTranslation} from 'react-i18next';
import {AuthenticationError} from '@/api/symfonyAuth.ts';
import {useAuth} from '@/auth/useAuth.ts';
import {Button} from '@/design-system/components/Button.tsx';
import {InlineAlert} from '@/design-system/components/InlineAlert.tsx';
import {TextField} from '@/design-system/components/TextField.tsx';

export function Login() {
  const {t} = useTranslation();
  const {login} = useAuth();
  const usernameRef = useRef<HTMLInputElement>(null);
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    usernameRef.current?.focus();
  }, []);

  async function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);

    try {
      await login(username.trim(), password);
      // La session publiée, `PublicOnlyRoute` renvoie vers l'écran demandé : rien d'autre à faire ici.
    } catch (caught) {
      // Le message de l'API (identifiants invalides, trop de tentatives) est affiché tel quel : il est en français et sans détail technique.
      setError(caught instanceof AuthenticationError ? caught.message : t('auth.authenticationFailed'));
      setPassword('');
      setPending(false);
    }
  }

  return (
    <div className="js-centered">
      <div className="js-auth-card">
        <div className="js-auth-brand">
          <img className="js-auth-logo" src="/favicon.png" alt="" />
          <h1 className="js-auth-name">JOBSCAN</h1>
          <p className="js-auth-tag">{t('auth.tagline')}</p>
        </div>

        {error && <InlineAlert tone="danger">{error}</InlineAlert>}

        <form className="js-auth-form" onSubmit={handleSubmit}>
          <TextField
            ref={usernameRef}
            label={t('auth.username')}
            name="username"
            autoComplete="username"
            required
            value={username}
            onChange={(event) => setUsername(event.target.value)}
          />
          <TextField
            label={t('auth.password')}
            name="password"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
          <Button type="submit" variant="primary" block disabled={pending}>
            {pending ? t('auth.submitting') : t('auth.submit')}
          </Button>
        </form>
      </div>
    </div>
  );
}
