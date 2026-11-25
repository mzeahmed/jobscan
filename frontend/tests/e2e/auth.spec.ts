import {expect, test} from '@playwright/test';
import {ACCESS_TOKEN, mockApi} from './mockApi.ts';

async function signIn(page: import('@playwright/test').Page, username = 'admin', password = 'secret') {
  await page.getByLabel('Identifiant').fill(username);
  await page.getByLabel('Mot de passe').fill(password);
  await page.getByRole('button', {name: 'Se connecter'}).click();
}

test.describe('connexion', () => {
  test('sans session, l’accueil renvoie vers la connexion', async ({page}) => {
    await mockApi(page);

    await page.goto('/');

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', {name: 'JOBSCAN'})).toBeVisible();
    await expect(page.getByLabel('Identifiant')).toBeFocused();
  });

  test('une connexion réussie ouvre l’accueil et joint l’API', async ({page}) => {
    const api = await mockApi(page);
    await page.goto('/login');

    await signIn(page);

    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', {name: 'Bienvenue sur Jobscan'})).toBeVisible();
    await expect(page.getByText('Connecté : admin')).toBeVisible();
    await expect(page.getByText('jobscan-api')).toBeVisible();
    await expect(page.getByText('Connectée', {exact: true})).toBeVisible();
    expect(api.logins).toEqual([{username: 'admin', password: 'secret'}]);
  });

  test('des identifiants invalides affichent le message de l’API et vident le mot de passe', async ({page}) => {
    await mockApi(page);
    await page.goto('/login');

    await signIn(page, 'admin', 'mauvais');

    await expect(page.getByRole('alert')).toHaveText('Identifiants invalides.');
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByLabel('Mot de passe')).toHaveValue('');
    await expect(page.getByLabel('Identifiant')).toHaveValue('admin');
  });

  test('un blocage pour trop de tentatives est annoncé tel que l’API le formule', async ({page}) => {
    await mockApi(page);
    await page.goto('/login');

    await signIn(page, 'verrouille', 'x');

    await expect(page.getByRole('alert')).toHaveText('Trop de tentatives : réessayez dans une minute.');
  });

  test('le formulaire exige les deux champs avant tout appel', async ({page}) => {
    const api = await mockApi(page);
    await page.goto('/login');

    await page.getByRole('button', {name: 'Se connecter'}).click();

    expect(api.logins).toEqual([]);
    await expect(page).toHaveURL(/\/login$/);
  });

  test('un identifiant saisi avec des espaces est nettoyé', async ({page}) => {
    const api = await mockApi(page);
    await page.goto('/login');

    await signIn(page, '  admin  ');

    await expect(page.getByRole('heading', {name: 'Bienvenue sur Jobscan'})).toBeVisible();
    expect(api.logins).toEqual([{username: 'admin', password: 'secret'}]);
  });
});

test.describe('session', () => {
  test('la session est restaurée au rechargement par le cookie de rafraîchissement', async ({page}) => {
    await mockApi(page, {session: true});

    await page.goto('/');

    await expect(page.getByRole('heading', {name: 'Bienvenue sur Jobscan'})).toBeVisible();
    await expect(page).toHaveURL('/');
  });

  test('un visiteur déjà connecté n’a pas à revoir la page de connexion', async ({page}) => {
    await mockApi(page, {session: true});

    await page.goto('/login');

    await expect(page).toHaveURL('/');
  });

  test('l’écran demandé avant la connexion est rouvert après', async ({page}) => {
    await mockApi(page);

    await page.goto('/analytics');
    await expect(page).toHaveURL(/\/login$/);
    await signIn(page);

    await expect(page).toHaveURL('/analytics');
    await expect(page.getByRole('heading', {name: 'Bientôt disponible'})).toBeVisible();
  });

  test('la déconnexion ferme la session et renvoie vers la connexion', async ({page}) => {
    const api = await mockApi(page, {session: true});
    await page.goto('/');

    await page.getByRole('button', {name: 'Déconnexion'}).click();

    await expect(page).toHaveURL(/\/login$/);
    expect(api.session).toBe(false);

    await page.reload();
    await expect(page).toHaveURL(/\/login$/);
  });

  test('une déconnexion que le serveur ne confirme pas laisse la session ouverte et le dit', async ({page}) => {
    await mockApi(page, {session: true, logoutStatus: 500});
    await page.goto('/');

    await page.getByRole('button', {name: 'Déconnexion'}).click();

    await expect(page.getByRole('alert')).toContainText('La déconnexion a échoué');
    await expect(page.getByText('Connecté : admin')).toBeVisible();
    await expect(page.getByRole('button', {name: 'Déconnexion'})).toBeEnabled();
  });

  test('une API indisponible au chargement n’est pas une déconnexion : écran dédié puis reprise', async ({page}) => {
    const api = await mockApi(page, {session: true, unavailable: true});
    await page.goto('/');

    await expect(page.getByRole('heading', {name: 'Service indisponible'})).toBeVisible();
    await expect(page.getByText('Cause : HTTP 503')).toBeVisible();

    api.unavailable = false;
    await page.getByRole('button', {name: 'Réessayer'}).click();

    await expect(page.getByRole('heading', {name: 'Bienvenue sur Jobscan'})).toBeVisible();
  });
});

test.describe('navigation', () => {
  test('une adresse inconnue affiche la page introuvable', async ({page}) => {
    await mockApi(page, {session: true});

    await page.goto('/n-importe-quoi');

    await expect(page.getByRole('heading', {name: 'Page introuvable'})).toBeVisible();
    await page.getByRole('link', {name: /Retour à l.accueil/}).click();
    await expect(page).toHaveURL('/');
  });

  test('les écrans non migrés annoncent qu’ils arrivent', async ({page}) => {
    await mockApi(page, {session: true});
    await page.goto('/');

    for (const [name, path] of [['Analytics', '/analytics']]) {
      await page.getByRole('navigation', {name: 'Navigation principale'}).getByRole('link', {name}).click();
      await expect(page).toHaveURL(path);
      await expect(page.getByRole('heading', {name: 'Bientôt disponible'})).toBeVisible();
    }
  });

  test('le lien actif de la navigation est signalé', async ({page}) => {
    await mockApi(page, {session: true});
    await page.goto('/jobs');

    const nav = page.getByRole('navigation', {name: 'Navigation principale'});

    await expect(nav.getByRole('link', {name: 'Offres'})).toHaveAttribute('aria-current', 'page');
    await expect(nav.getByRole('link', {name: 'Accueil'})).not.toHaveAttribute('aria-current', 'page');
  });

  test('le lien d’évitement mène au contenu principal au clavier', async ({page}) => {
    await mockApi(page, {session: true});
    await page.goto('/');
    await expect(page.getByRole('heading', {name: 'Bienvenue sur Jobscan'})).toBeVisible();

    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', {name: 'Aller au contenu'})).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(page.locator('#contenu')).toBeFocused();
  });
});

test.describe('sécurité du navigateur', () => {
  test('la CSP de production est servie, sans « unsafe-inline » ni source externe de script', async ({page}) => {
    await mockApi(page);

    const response = await page.goto('/login');
    const csp = response?.headers()['content-security-policy'] ?? '';

    expect(csp).toContain("script-src 'self'");
    expect(csp).not.toContain("'unsafe-inline'");
    expect(csp).toContain("frame-ancestors 'none'");
    expect(csp).toContain("default-src 'none'");
  });

  test('aucune violation de CSP sur un parcours complet', async ({page}) => {
    const violations: string[] = [];
    page.on('console', (message) => {
      if (/Content Security Policy|Refused to/i.test(message.text())) {
        violations.push(message.text());
      }
    });
    await mockApi(page);

    await page.goto('/login');
    await signIn(page);
    await expect(page.getByText('Connectée', {exact: true})).toBeVisible();
    await page.getByRole('link', {name: 'Offres'}).click();
    await page.getByRole('button', {name: 'Déconnexion'}).click();
    await expect(page).toHaveURL(/\/login$/);

    expect(violations).toEqual([]);
  });

  test('le jeton d’accès reste en mémoire : ni stockage local, ni stockage de session, ni cookie lisible', async ({
    page
  }) => {
    await mockApi(page);
    await page.goto('/login');
    await signIn(page);
    await expect(page.getByRole('heading', {name: 'Bienvenue sur Jobscan'})).toBeVisible();

    // Expression évaluée dans la page : tout ce que le navigateur garde (stockages et cookies lisibles par script).
    const stored = await page.evaluate<string>(
      'JSON.stringify({local: {...localStorage}, session: {...sessionStorage}, cookie: document.cookie})'
    );

    expect(stored).not.toContain(ACCESS_TOKEN);
    expect(stored).not.toMatch(/token|jwt|access/i);
  });

  test('le mot de passe n’apparaît jamais dans l’adresse', async ({page}) => {
    await mockApi(page);
    await page.goto('/login');

    await signIn(page, 'admin', 'secret');
    await expect(page).toHaveURL('/');

    expect(page.url()).not.toContain('secret');
  });
});
