import {expect, test} from '@playwright/test';
import {mockApi} from './mockApi.ts';
import {emptyReport, sampleReport} from './mockAnalytics.ts';

test.describe('écran Analytics', () => {
  test('affiche le parcours, les répartitions et le rendement des sources', async ({page}) => {
    const api = await mockApi(page, {session: true, report: sampleReport()});
    await page.goto('/analytics');

    await expect(page.getByRole('heading', {name: 'Analytics', level: 1})).toBeVisible();
    const funnel = page.getByRole('region', {name: 'Parcours'});
    await expect(funnel.getByText('4/8 (50 %)')).toBeVisible();
    await expect(funnel.getByText('2/8 (25 %)')).toBeVisible();
    await expect(funnel.getByText('6 jours')).toBeVisible();
    await expect(page.getByRole('region', {name: 'Par source'}).getByRole('row', {name: /lever/})).toContainText(
      '3/6 (50 %)'
    );
    await expect(
      page.getByRole('region', {name: 'Rendement des sources'}).getByRole('row', {name: /lever/})
    ).toContainText('120');
    expect(api.analytics.queries).toEqual(['90d']);
  });

  test('sous l’effectif minimal, seul « x/y » est affiché, jamais un pourcentage', async ({page}) => {
    await mockApi(page, {session: true, report: sampleReport()});
    await page.goto('/analytics');

    const row = page.getByRole('region', {name: 'Par source'}).getByRole('row', {name: /rss/});
    await expect(row).toContainText('1/2');
    await expect(row).toContainText('0/2');
    await expect(row).not.toContainText('%');
  });

  test('la période se choisit dans l’adresse et une valeur inconnue est ignorée', async ({page}) => {
    const api = await mockApi(page, {session: true, report: sampleReport()});
    await page.goto('/analytics');
    await page.getByRole('navigation', {name: 'Période'}).getByRole('link', {name: '30 jours'}).click();

    await expect(page).toHaveURL(/\/analytics\?since=30d$/);
    await expect(page.getByRole('link', {name: '30 jours'})).toHaveAttribute('aria-current', 'page');
    await expect.poll(() => api.analytics.queries).toContain('30d');

    await page.goto('/analytics?since=7d');
    await expect(page.getByRole('link', {name: '90 jours'})).toHaveAttribute('aria-current', 'page');
    expect(api.analytics.queries).not.toContain('7d');
  });

  test('sans candidature envoyée, un état vide explicatif remplace le parcours', async ({page}) => {
    await mockApi(page, {session: true, report: {...emptyReport(), sourcing: sampleReport().sourcing}});
    await page.goto('/analytics');

    await expect(page.getByRole('heading', {name: /Aucune candidature envoyée/})).toBeVisible();
    await expect(page.getByRole('region', {name: 'Parcours'})).toHaveCount(0);
    await expect(page.getByRole('region', {name: 'Rendement des sources'})).toBeVisible();
  });

  test('une erreur serveur propose de réessayer', async ({page}) => {
    const api = await mockApi(page, {session: true, report: sampleReport()});
    api.analytics.status = 500;
    await page.goto('/analytics');

    await expect(page.getByRole('alert')).toContainText('Impossible de charger les statistiques');
    api.analytics.status = 0;
    await page.getByRole('button', {name: 'Réessayer'}).click();
    await expect(page.getByRole('region', {name: 'Parcours'})).toBeVisible();
  });

  test('un libellé hostile est affiché comme du texte', async ({page}) => {
    const report = sampleReport();
    report.breakdowns.source[0]!.label = '<img src=x onerror=alert(1)>{{7*7}}';
    await mockApi(page, {session: true, report});
    await page.goto('/analytics');

    await expect(page.getByRole('cell', {name: '<img src=x onerror=alert(1)>{{7*7}}'})).toHaveCount(0);
    await expect(page.getByRole('rowheader', {name: '<img src=x onerror=alert(1)>{{7*7}}'})).toBeVisible();
    await expect(page.locator('img[src="x"]')).toHaveCount(0);
  });
});
