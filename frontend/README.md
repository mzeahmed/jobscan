# Jobscan — front React

Interface de Jobscan, qui consomme l'API Symfony `/api/v1` (voir `../docs/API.md`).

```bash
npm install
npm run dev          # http://localhost:5173 (relaie /api et /health vers Symfony)
npm run typecheck && npm run lint && npm run format:check && npm run build
npm run e2e:install  # une fois : navigateur Playwright
npm run e2e          # tests de bout en bout (build de production, API simulée)
```

Conventions : un module par domaine (`src/modules/<Nom>`), `*Api.ts` → `*Keys.ts` → `use*.ts` → composant,
état serveur uniquement dans TanStack Query, aucun style en ligne (CSP), icônes via `<Icon>`, textes dans `i18n/fr.ts`.
Le jeton d'accès reste en mémoire ; le cookie de rafraîchissement est HttpOnly.
