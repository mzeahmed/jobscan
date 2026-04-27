# Roadmap JOBSCAN

> Les contributions sont les bienvenues sur n'importe quelle phase. Consultez [CONTRIBUTING.md](CONTRIBUTING.md) pour démarrer.

---

## Phase 1 — MVP & stabilisation

Objectif : un pipeline fonctionnel, fiable et reproductible en local.

- [x] Agrégation d'offres via flux RSS
- [x] Agrégation d'offres via SearXNG
- [x] Filtre par mots-clés et stack technique
- [x] Pré-scoring heuristique (sans IA)
- [x] Analyse IA locale via LM Studio (API compatible OpenAI)
- [x] Scoring final sur 100 avec breakdown
- [x] Persistance SQLite via Doctrine
- [x] Alerte Telegram pour les meilleures offres
- [x] Configuration centralisée dans `jobscan.yaml`
- [x] Hook pre-push avec PHPStan
- [x] Tests unitaires sur `ScoringService`
- [ ] Tests d'intégration sur `JobProcessor`
- [ ] CI GitHub Actions (lint + PHPStan)

---

## Phase 2 — Qualité des opportunités

Objectif : réduire le bruit, améliorer la précision des offres remontées.

- [ ] Déduplication avancée (similarité titre + source, pas seulement URL)
- [ ] Filtre sur l'ancienneté de l'offre (ignorer > 30 jours)
- [ ] Détection des doublons cross-providers
- [ ] Ajout d'un provider LinkedIn (scraping ou API)
- [ ] Ajout d'un provider Indeed / Welcome to the Jungle
- [ ] Support multi-modèles LM Studio (configurable par `jobscan.yaml`)
- [ ] Enrichissement du scoring par séniorité et budget détecté
- [ ] Normalisation des données IA (contrat, remote, budget) en valeurs typées

---

## Phase 3 — Productivité utilisateur

Objectif : rendre JOBSCAN utilisable au quotidien sans effort.

- [ ] Commande `app:jobs:stats` — résumé hebdomadaire des offres
- [ ] Commande `app:jobs:purge` — suppression des offres anciennes
- [ ] Mode dry-run sur la pipeline (`--dry-run`)
- [ ] Flag `--provider=rss` pour n'exécuter qu'un provider
- [ ] Dashboard web minimal (liste des offres + score)
- [ ] Export CSV/JSON des offres scorées
- [ ] Notification configurable : seuil de score minimum pour alerte

---

## Phase 4 — Scalabilité

Objectif : passer à un déploiement plus robuste et multi-utilisateurs.

- [ ] Migration optionnelle vers PostgreSQL ou MySQL
- [ ] Support de plusieurs profils utilisateur (stack différente, localisations)
- [ ] Planification via cron intégré Symfony (Scheduler)
- [ ] Dockerisation complète (app + SearXNG + LM Studio)
- [ ] API REST pour exposer les offres et les scores
- [ ] Internationalisation des prompts IA (EN / FR configurable)
