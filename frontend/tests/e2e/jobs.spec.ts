import {expect, type Page, test} from '@playwright/test';
import {mockApi} from './mockApi.ts';
import {makeJob, type MockJob} from './mockJobs.ts';

const many = (count: number): MockJob[] => Array.from({length: count}, (_, index) => makeJob(index + 1));

async function openJobs(page: Page, jobs: MockJob[], path = '/jobs') {
  const api = await mockApi(page, {session: true, jobs});
  await page.goto(path);

  return api;
}

const card = (page: Page, title: string) => page.getByRole('article').filter({hasText: title});

test.describe('liste des offres', () => {
  test('affiche les offres, leur nombre et un score lisible', async ({page}) => {
    await openJobs(page, [makeJob(1, {title: 'Dev Symfony', score: 82}), makeJob(2, {title: 'Dev React', score: 30})]);

    await expect(page.getByRole('heading', {name: 'Offres', level: 1})).toBeVisible();
    await expect(page.getByText('2 offres analysées et scorées')).toBeVisible();
    await expect(page.getByRole('img', {name: 'Score de pertinence : 82 sur 100'})).toBeVisible();
    await expect(card(page, 'Dev React').getByRole('img', {name: 'Score de pertinence : 30 sur 100'})).toBeVisible();
  });

  test('un score inconnu n’est pas présenté comme un score nul', async ({page}) => {
    await openJobs(page, [makeJob(1, {title: 'Sans score', score: null})]);

    await expect(page.getByRole('img', {name: 'Score non calculé'})).toBeVisible();
    await expect(page.getByRole('img', {name: /Score de pertinence/})).toHaveCount(0);
  });

  test('chaque état est annoncé : chargement, vide, erreur', async ({page}) => {
    const api = await openJobs(page, []);
    await expect(page.getByRole('heading', {name: 'Aucune offre'})).toBeVisible();
    await expect(page.getByText(/Rien à trier pour l.instant/)).toBeVisible();

    api.jobs.listStatus = 500;
    await page.reload();
    await expect(page.getByRole('alert').filter({hasText: 'Impossible de charger les offres'})).toBeVisible();

    api.jobs.listStatus = 0;
    await page.getByRole('button', {name: 'Réessayer'}).click();
    await expect(page.getByRole('heading', {name: 'Aucune offre'})).toBeVisible();
  });
});

test.describe('contenu non fiable', () => {
  test('le HTML d’une annonce est affiché comme du texte, jamais interprété', async ({page}) => {
    await openJobs(page, [
      makeJob(1, {
        title: '<img src=x onerror=alert(1)>Piège',
        excerpt: '<script>window.__pwned = true</script>texte',
        company: '<b>Gras</b>'
      })
    ]);

    await expect(page.getByText('<img src=x onerror=alert(1)>Piège')).toBeVisible();
    await expect(page.getByText('<b>Gras</b>')).toBeVisible();
    expect(await page.locator('article img, article b, article script').count()).toBe(0);
    expect(await page.evaluate<boolean>('window.__pwned === true')).toBe(false);
  });

  test('le lien de l’annonce s’ouvre sans transmettre l’origine, et n’existe pas sans URL sûre', async ({page}) => {
    await openJobs(page, [makeJob(1, {title: 'Avec lien'}), makeJob(2, {title: 'Sans lien', url: null})]);

    const link = card(page, 'Avec lien').getByRole('link', {name: /Avec lien/});
    await expect(link).toHaveAttribute('href', 'https://jobs.example/1');
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', /noopener/);
    await expect(link).toHaveAttribute('rel', /noreferrer/);
    await expect(card(page, 'Sans lien').getByRole('link', {name: /Sans lien/})).toHaveCount(0);
    await expect(card(page, 'Sans lien').getByRole('heading', {name: 'Sans lien'})).toBeVisible();
  });
});

test.describe('filtres et pagination dans l’adresse', () => {
  test('filtrer écrit les filtres dans l’adresse et les envoie à l’API', async ({page}) => {
    const api = await openJobs(page, many(6));

    await page.getByLabel('Source').selectOption('rss');
    await page.getByLabel('Score minimum').fill('40');
    await page.getByRole('button', {name: 'Filtrer'}).click();

    await expect(page).toHaveURL(/source=rss/);
    await expect(page).toHaveURL(/min_score=40/);
    await expect.poll(() => api.jobs.listQueries.at(-1)).toContain('source=rss');
    expect(api.jobs.listQueries.at(-1)).toContain('min_score=40');
    await expect(page.locator('.js-page-subtitle')).toHaveText('3 offres correspondent aux filtres');
  });

  test('une adresse avec filtres restaure le formulaire, et les valeurs absurdes sont ignorées', async ({page}) => {
    const api = await openJobs(page, many(4), '/jobs?source=lever&min_score=999&sort=hasard&page=-3&remote=peut-etre');

    await expect(page.getByLabel('Source')).toHaveValue('lever');
    await expect(page.getByLabel('Score minimum')).toHaveValue('');
    await expect(page.getByLabel('Tri')).toHaveValue('date');
    expect(api.jobs.listQueries.at(0)).toBe('source=lever');
  });

  test('réinitialiser retire les filtres', async ({page}) => {
    await openJobs(page, many(4), '/jobs?source=lever');

    await page.getByRole('button', {name: 'Réinitialiser'}).click();

    await expect(page).toHaveURL(/\/jobs$/);
    await expect(page.getByLabel('Source')).toHaveValue('');
  });

  test('pagine, garde la page dans l’adresse et suit le bouton précédent du navigateur', async ({page}) => {
    await openJobs(page, many(25));
    await expect(page.getByText('Page 1 sur 3')).toBeVisible();
    await expect(page.getByRole('button', {name: 'Précédent'})).toBeDisabled();

    await page.getByRole('button', {name: 'Suivant'}).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(page.getByText('Page 2 sur 3')).toBeVisible();

    await page.getByRole('button', {name: 'Suivant'}).click();
    await expect(page.getByText('Page 3 sur 3')).toBeVisible();
    await expect(page.getByRole('button', {name: 'Suivant'})).toBeDisabled();

    await page.goBack();
    await expect(page.getByText('Page 2 sur 3')).toBeVisible();
  });

  test('un seul filtre à la fois remet la liste à la première page', async ({page}) => {
    await openJobs(page, many(25), '/jobs?page=3');
    await expect(page.getByText('Page 3 sur 3')).toBeVisible();

    await page.getByLabel('Tri').selectOption('score');
    await page.getByRole('button', {name: 'Filtrer'}).click();

    await expect(page.getByText('Page 1 sur 3')).toBeVisible();
    await expect(page).toHaveURL(/sort=score/);
    await expect(page).not.toHaveURL(/page=/);
  });
});

test.describe('actions', () => {
  test('suivre une offre ouvre un brouillon et donne accès à la candidature', async ({page}) => {
    await openJobs(page, [makeJob(1, {title: 'À suivre'})]);

    await card(page, 'À suivre').getByRole('button', {name: 'Suivre'}).click();

    const application = card(page, 'À suivre').getByRole('link', {name: 'Candidature'});
    await expect(application).toBeVisible();
    await expect(application).toHaveAttribute('href', '/applications/100');
    await expect(card(page, 'À suivre').getByRole('button', {name: 'Suivre'})).toHaveCount(0);
  });

  test('une offre déjà suivie propose directement la candidature', async ({page}) => {
    await openJobs(page, [makeJob(1, {title: 'Déjà suivie', application_id: 7})]);

    await expect(card(page, 'Déjà suivie').getByRole('link', {name: 'Candidature'})).toHaveAttribute(
      'href',
      '/applications/7'
    );
  });

  test('sauvegarder retire l’offre de la vue « à trier » et la retrouve dans « Sauvegardées »', async ({page}) => {
    await openJobs(page, [makeJob(1, {title: 'À garder'}), makeJob(2, {title: 'Autre'})]);

    await card(page, 'À garder').getByRole('button', {name: 'Sauvegarder'}).click();

    await expect(card(page, 'À garder')).toHaveCount(0);
    await expect(card(page, 'Autre')).toBeVisible();

    await page.getByLabel('Affichage').selectOption('saved');
    await page.getByRole('button', {name: 'Filtrer'}).click();

    await expect(card(page, 'À garder')).toBeVisible();
    await expect(card(page, 'À garder').getByText('Sauvegardée')).toBeVisible();
    await expect(card(page, 'À garder').getByRole('button', {name: 'Sauvegarder'})).toHaveCount(0);
  });

  test('retirer la décision remet l’offre à trier', async ({page}) => {
    await openJobs(
      page,
      [makeJob(1, {title: 'Décidée', user_state: {value: 'ignored', label: 'Ignorée'}})],
      '/jobs?state=ignored'
    );

    await card(page, 'Décidée').getByRole('button', {name: 'Retirer la décision'}).click();

    await expect(page.getByRole('heading', {name: 'Aucune offre'})).toBeVisible();
    await page.goto('/jobs');
    await expect(card(page, 'Décidée')).toBeVisible();
  });

  test('une action qui échoue est signalée et l’offre reste utilisable', async ({page}) => {
    const api = await openJobs(page, [makeJob(1, {title: 'Fragile'})]);
    api.jobs.writeStatus = 500;

    await card(page, 'Fragile').getByRole('button', {name: 'Ignorer'}).click();

    await expect(page.getByRole('alert').filter({hasText: /L.action a échoué/})).toBeVisible();
    await expect(card(page, 'Fragile')).toBeVisible();
    await expect(card(page, 'Fragile').getByRole('button', {name: 'Ignorer'})).toBeEnabled();
  });
});
