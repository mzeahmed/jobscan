import {expect, type Page, test} from '@playwright/test';
import {mockApi} from './mockApi.ts';
import {type MockApplication, makeApplication, withStatus} from './mockApplications.ts';

async function open(
  page: Page,
  applications: MockApplication[],
  path = '/applications',
  cvVersions: {id: string; label: string}[] = []
) {
  const api = await mockApi(page, {session: true, applications, cvVersions});
  await page.goto(path);

  return api;
}

const row = (page: Page, title: string) => page.getByRole('article').filter({hasText: title});

test.describe('liste des candidatures', () => {
  test('affiche les candidatures avec leur statut, et les onglets avec leurs compteurs', async ({page}) => {
    await open(page, [
      makeApplication(1, {status: withStatus('applied')}),
      makeApplication(2, {status: withStatus('applied')}),
      makeApplication(3, {status: withStatus('rejected')})
    ]);

    await expect(page.getByRole('heading', {name: 'Candidatures', level: 1})).toBeVisible();
    await expect(page.getByText('3 candidatures suivies')).toBeVisible();
    const tabs = page.getByRole('navigation', {name: 'Statuts'});
    await expect(tabs.getByRole('link', {name: /Toutes\s*3/})).toHaveAttribute('aria-current', 'page');
    await expect(tabs.getByRole('link', {name: /Postulé\s*2/})).toBeVisible();
    await expect(tabs.getByRole('link', {name: /Refusée\s*1/})).toBeVisible();
    await expect(tabs.getByRole('link', {name: /Offre\b/})).toHaveCount(0);
  });

  test('un onglet filtre par statut dans l’adresse', async ({page}) => {
    const api = await open(page, [
      makeApplication(1, {status: withStatus('applied')}),
      makeApplication(2, {status: withStatus('rejected')})
    ]);

    await page.getByRole('link', {name: /Refusée/}).click();

    await expect(page).toHaveURL(/status=rejected/);
    await expect(row(page, 'Offre suivie 2')).toBeVisible();
    await expect(row(page, 'Offre suivie 1')).toHaveCount(0);
    expect(api.applications.listQueries.at(-1)).toBe('status=rejected');
  });

  test('un statut inconnu dans l’adresse est ignoré et jamais envoyé à l’API', async ({page}) => {
    const api = await open(page, [makeApplication(1)], '/applications?status=embauche');

    await expect(row(page, 'Offre suivie 1')).toBeVisible();
    expect(api.applications.listQueries).toEqual(['']);
  });

  test('signale les relances dues et la date de relance', async ({page}) => {
    await open(page, [
      makeApplication(1, {
        status: withStatus('applied'),
        follow_up_at: '2026-11-10T00:00:00+00:00',
        follow_up_due: true
      })
    ]);

    await expect(page.getByRole('status').or(page.getByText('1 relance est due.'))).toBeVisible();
    await expect(row(page, 'Offre suivie 1').getByText('Relance : 10/11/2026')).toBeVisible();
    await expect(row(page, 'Offre suivie 1').getByText('Relance due')).toBeVisible();
  });

  test('explique l’état vide et renvoie vers les offres', async ({page}) => {
    await open(page, []);

    await expect(page.getByRole('heading', {name: 'Aucune candidature'})).toBeVisible();
    await page.getByRole('link', {name: 'Voir les offres'}).click();
    await expect(page).toHaveURL(/\/jobs$/);
  });

  test('une erreur du serveur est annoncée et on peut réessayer', async ({page}) => {
    const api = await open(page, [makeApplication(1)]);
    api.applications.listStatus = 500;
    await page.reload();

    await expect(page.getByRole('alert').filter({hasText: 'Impossible de charger les candidatures'})).toBeVisible();

    api.applications.listStatus = 0;
    await page.getByRole('button', {name: 'Réessayer'}).click();
    await expect(row(page, 'Offre suivie 1')).toBeVisible();
  });

  test('le HTML d’une annonce reste du texte, et le lien de l’offre est sûr', async ({page}) => {
    await open(page, [
      makeApplication(1, {
        job: {id: 1, title: '<img src=x onerror=alert(1)>Piège', company: '<b>Gras</b>', location: null, url: null}
      })
    ]);

    await expect(page.getByText('<img src=x onerror=alert(1)>Piège')).toBeVisible();
    expect(await page.locator('article img, article b').count()).toBe(0);
    await expect(page.getByRole('link', {name: /Voir l.offre/})).toHaveCount(0);
  });
});

test.describe('détail d’une candidature', () => {
  test('affiche l’offre, le statut, les informations et l’historique du plus récent au plus ancien', async ({page}) => {
    await open(
      page,
      [
        makeApplication(1, {
          status: withStatus('applied'),
          notes: 'Une note',
          recruiter_name: 'Camille Martin',
          history: [
            {from: null, to: withStatus('draft'), note: null, changed_at: '2026-11-18T10:00:00+00:00'},
            {
              from: withStatus('draft'),
              to: withStatus('applied'),
              note: 'Envoyée par mail',
              changed_at: '2026-11-19T10:00:00+00:00'
            }
          ]
        })
      ],
      '/applications/1'
    );

    await expect(page.getByRole('heading', {name: 'Offre suivie 1', level: 1})).toBeVisible();
    await expect(page.getByLabel('Recruteur', {exact: true})).toHaveValue('Camille Martin');
    await expect(page.getByLabel('Notes')).toHaveValue('Une note');
    const steps = page.getByRole('list', {name: 'Historique'}).getByRole('listitem');
    await expect(steps).toHaveCount(2);
    await expect(steps.first()).toContainText('Brouillon → Postulé');
    await expect(steps.first()).toContainText('Envoyée par mail');
    await expect(steps.last()).toContainText('Brouillon');
  });

  test('ne propose que les transitions autorisées', async ({page}) => {
    await open(page, [makeApplication(1, {status: withStatus('draft')})], '/applications/1');

    await expect(page.getByLabel('Nouveau statut')).toBeVisible();
    const options = await page.getByLabel('Nouveau statut').locator('option').allTextContents();

    expect(options).toEqual(['— choisir —', 'Postulé', 'Retirée']);
  });

  test('change le statut avec une date et une note, et met l’historique à jour', async ({page}) => {
    const api = await open(page, [makeApplication(1, {status: withStatus('draft')})], '/applications/1');

    await page.getByLabel('Nouveau statut').selectOption('applied');
    await page.getByLabel(/Date \(/).fill('2026-11-24');
    await page.getByLabel('Note (facultative)').fill('Envoyée par mail');
    await page.getByRole('button', {name: 'Changer le statut'}).click();

    await expect(page.getByText('Statut : Postulé.')).toBeVisible();
    expect(api.applications.statusPosts).toEqual([{status: 'applied', at: '2026-11-24', note: 'Envoyée par mail'}]);
    const steps = page.getByRole('list', {name: 'Historique'}).getByRole('listitem');
    await expect(steps).toHaveCount(2);
    await expect(steps.first()).toContainText('Brouillon → Postulé');
    await expect(page.getByLabel('Nouveau statut').locator('option')).toContainText(['— choisir —', 'Entretien']);
  });

  test('sans date ni note, n’envoie que le statut', async ({page}) => {
    const api = await open(page, [makeApplication(1, {status: withStatus('draft')})], '/applications/1');

    await page.getByLabel('Nouveau statut').selectOption('applied');
    await page.getByRole('button', {name: 'Changer le statut'}).click();

    await expect(page.getByText('Statut : Postulé.')).toBeVisible();
    expect(api.applications.statusPosts).toEqual([{status: 'applied'}]);
  });

  test('un statut final n’a plus de formulaire de changement', async ({page}) => {
    await open(page, [makeApplication(1, {status: withStatus('rejected')})], '/applications/1');

    await expect(page.getByText(/statut final/)).toBeVisible();
    await expect(page.getByRole('button', {name: 'Changer le statut'})).toHaveCount(0);
  });

  test('une transition refusée par l’API est signalée et le statut ne change pas', async ({page}) => {
    await open(page, [makeApplication(1, {status: withStatus('draft')})], '/applications/1');
    // L'API a refusé entre-temps : on force la transition pour voir le message.
    await page.route('**/api/v1/applications/1/status', (route) =>
      route.fulfill({
        status: 422,
        json: {
          code: 'invalid_transition',
          success: false,
          data: {message: 'Transition interdite : Brouillon → Proposition.'}
        }
      })
    );

    await page.getByLabel('Nouveau statut').selectOption('applied');
    await page.getByRole('button', {name: 'Changer le statut'}).click();

    await expect(page.getByRole('alert')).toContainText('Transition interdite');
    await expect(page.getByRole('button', {name: 'Changer le statut'})).toBeEnabled();
  });

  test('enregistre les informations, efface les champs vidés et n’envoie pas le CV sans profil', async ({page}) => {
    const api = await open(
      page,
      [makeApplication(1, {status: withStatus('applied'), notes: 'À effacer', recruiter_contact: 'a@b.fr'})],
      '/applications/1'
    );

    await page.getByLabel('Recruteur', {exact: true}).fill('  Camille Martin ');
    await page.getByLabel('Canal').selectOption('email');
    await page.getByLabel('Date de relance').fill('2026-12-20');
    await page.getByLabel('Notes').fill('   ');
    await page.getByLabel('Contact du recruteur').fill('');
    await page.getByRole('button', {name: 'Enregistrer'}).click();

    await expect(page.getByText('Informations enregistrées.')).toBeVisible();
    expect(api.applications.patches).toEqual([
      {
        recruiter_name: 'Camille Martin',
        recruiter_contact: null,
        channel: 'email',
        follow_up_at: '2026-12-20',
        message_sent: null,
        notes: null
      }
    ]);
    await expect(page.getByLabel('Notes')).toHaveValue('');
    await expect(page.getByLabel('Date de relance')).toHaveValue('2026-12-20');
  });

  test('propose les versions de CV du profil et les envoie', async ({page}) => {
    const api = await open(page, [makeApplication(1)], '/applications/1', [
      {id: 'backend-php', label: 'CV Backend PHP'}
    ]);

    await page.getByLabel('Version du CV').selectOption('backend-php');
    await page.getByRole('button', {name: 'Enregistrer'}).click();

    await expect(page.getByText('Informations enregistrées.')).toBeVisible();
    expect(api.applications.patches[0]).toMatchObject({cv: 'backend-php'});
  });

  test('une erreur de champ de l’API s’affiche sous le champ et garde la saisie', async ({page}) => {
    await open(page, [makeApplication(1)], '/applications/1');

    await page.getByLabel('Recruteur', {exact: true}).fill('refusé');
    await page.getByLabel('Notes').fill('Ma note à garder');
    await page.getByRole('button', {name: 'Enregistrer'}).click();

    await expect(page.getByText('255 caractères au maximum.')).toBeVisible();
    await expect(page.getByLabel('Recruteur', {exact: true})).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Notes')).toHaveValue('Ma note à garder');
  });

  test('une panne d’écriture est annoncée sans perdre la saisie', async ({page}) => {
    const api = await open(page, [makeApplication(1)], '/applications/1');
    api.applications.writeStatus = 500;

    await page.getByLabel('Notes').fill('Ne pas perdre');
    await page.getByRole('button', {name: 'Enregistrer'}).click();

    await expect(page.getByRole('alert')).toContainText('L’enregistrement a échoué');
    await expect(page.getByLabel('Notes')).toHaveValue('Ne pas perdre');
  });

  test('le texte saisi est rendu tel quel, jamais interprété', async ({page}) => {
    await open(
      page,
      [makeApplication(1, {notes: '<script>window.__pwned = true</script> {{7*7}}'})],
      '/applications/1'
    );

    await expect(page.getByLabel('Notes')).toHaveValue('<script>window.__pwned = true</script> {{7*7}}');
    expect(await page.evaluate<boolean>('window.__pwned === true')).toBe(false);
  });

  test('une candidature inconnue ou une adresse absurde donnent « introuvable »', async ({page}) => {
    await open(page, [makeApplication(1)], '/applications/999');
    await expect(page.getByRole('heading', {name: 'Candidature introuvable'})).toBeVisible();

    await page.goto('/applications/abc');
    await expect(page.getByRole('heading', {name: 'Candidature introuvable'})).toBeVisible();

    await page.getByRole('link', {name: 'Candidatures', exact: true}).last().click();
    await expect(page).toHaveURL(/\/applications$/);
  });

  test('depuis la liste, ouvre le détail', async ({page}) => {
    await open(page, [makeApplication(7)]);

    await row(page, 'Offre suivie 7').getByRole('link', {name: 'Offre suivie 7', exact: true}).click();

    await expect(page).toHaveURL(/\/applications\/7$/);
    await expect(page.getByRole('heading', {name: 'Offre suivie 7', level: 1})).toBeVisible();
  });
});
