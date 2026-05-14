# JOBSCAN — Roadmap

> Les contributions sont les bienvenues à chaque phase. Consultez [CONTRIBUTING.md](CONTRIBUTING.md) pour démarrer.

---

## Vision

JOBSCAN est un pipeline d'intelligence emploi, local et sans coût externe.

Il remplace la navigation manuelle sur les job boards par un pipeline automatisé : ingestion d'offres brutes depuis RSS et recherche web, filtrage du bruit, analyse de chaque offre par un LLM local, scoring selon un profil configuré, et envoi des meilleures opportunités sur Telegram.

**Contraintes de conception non négociables :**
- Aucune dépendance à une API externe payante
- Fonctionne entièrement sur la machine du développeur
- Un seul fichier YAML pour reconfigurer pour n'importe quel profil technique
- Pipeline en ligne de commande Symfony — aucun dashboard requis pour être utile

---

## Architecture

```
Providers (RSS + SearXNG)
    → JobProcessor (filtre + dédup)
        → AIClient (LM Studio / LLM local)
            → ScoringService
                → JobRepository (SQLite)
                    → NotificationService (Telegram)
```

| Fichier | Rôle |
|---------|------|
| `src/Service/Provider/JobProviderInterface.php` | Contrat de tous les providers |
| `src/Service/Processor/JobProcessor.php` | Déduplication, filtrage, orchestration |
| `src/Service/AI/AIClient.php` | API LM Studio + fallback heuristique |
| `src/Service/Scoring/ScoringService.php` | Calcul du score |
| `src/Service/Notification/TelegramNotifier.php` | Alerte Telegram |
| `config/packages/jobscan.yaml` | Toute la configuration métier |

---

## Phase 1 — MVP & Stabilisation ✅

- [x] Agrégation d'offres via flux RSS (`RsFeedProvider`)
- [x] Agrégation d'offres via SearXNG (`SearxProvider`)
- [x] Filtre par mots-clés et stack technique
- [x] Pré-scoring heuristique (sans IA)
- [x] Analyse IA locale via LM Studio (API compatible OpenAI)
- [x] Scoring final sur 100 avec breakdown
- [x] Persistance SQLite via Doctrine
- [x] Alerte Telegram pour les meilleures offres
- [x] Configuration centralisée dans `jobscan.yaml`
- [x] Hook pre-push avec PHPStan
- [x] Tests unitaires sur `ScoringService`
- [x] CI GitHub Actions
- [ ] Tests d'intégration sur `JobProcessor` — #17

---

## Phase 2 — Qualité du signal

- [x] Filtrer les offres trop anciennes — #1
- [x] Déduplication avancée cross-provider — #2
- [ ] Source RSS basée sur le domaine du flux — #3
- [ ] Pagination SearXNG — #4
- [ ] Délai configurable entre les requêtes SearXNG — #5
- [ ] Modèle LM Studio configurable via `jobscan.yaml` — #6
- [ ] Normalisation typée de la sortie IA — #7
- [ ] Bonus de scoring par séniorité et budget — #8

---

## Phase 3 — Robustesse & Tests

- [ ] Retry avec backoff exponentiel sur les appels IA — #9
- [ ] Circuit breaker sur LM Studio — #10
- [ ] Batch insert Doctrine (flush groupé) — #11
- [ ] Déclarations `.PHONY` dans le Makefile — #12
- [ ] Sécurisation du Dockerfile (non-root, multi-stage) — #13
- [ ] Interface `isHealthy()` sur les providers — #14
- [ ] Tests unitaires : `SearxProvider::isClearlyIrrelevant()` — #15
- [ ] Tests unitaires : fallback heuristique de `AIClient` — #16
- [ ] Tests d'intégration : `JobProcessor` — #17

---

## Phase 4 — Productivité développeur

- [ ] Commande `app:jobs:purge` — #19
- [ ] Commande `app:jobs:stats` — #20
- [ ] Mode `--dry-run` sur le pipeline — #21
- [ ] Flag `--provider` pour filtrer les sources — #22
- [ ] Résumé de fin de run — #23
- [ ] Notifications Telegram enrichies — #24
- [ ] Seuil de notification configurable — #25
- [ ] Poids du scoring configurables via YAML — #26
- [ ] Export CSV / JSON — #27

---

## Phase 5 — Scalabilité

- [ ] Prompt IA configurable FR/EN — #28
- [ ] Intégration Symfony Scheduler — #29
- [ ] Compatibilité PostgreSQL / MySQL — #30
- [ ] Support multi-profils utilisateur — #31
- [ ] API REST en lecture seule — #32
- [ ] Stack Docker complète avec LLM conteneurisé — #33

---

## Bonnes premières contributions

Tâches bien délimitées, idéales pour une première PR :

- #1 — Filtre sur l'ancienneté des offres
- #3 — Source RSS basée sur le domaine du flux
- #4 — Pagination SearXNG
- #5 — Délai configurable entre les requêtes SearXNG
- #11 — Batch insert Doctrine
- #12 — Déclarations `.PHONY` dans le Makefile
- #15 — Tests unitaires `SearxProvider::isClearlyIrrelevant()`
- #19 — Commande `app:jobs:purge`
- #21 — Mode `--dry-run`
- #23 — Résumé de fin de run
- #24 — Notifications Telegram enrichies
- #25 — Seuil de notification configurable
- #27 — Export CSV / JSON
- #28 — Prompt IA configurable FR/EN

---

## Comment contribuer

```bash
git clone https://github.com/mzeahmed/jobscan.git
cd jobscan
make setup
composer install
cp .env .env.local
make migrate
```

Avant d'ouvrir une PR :

```bash
make stan    # PHPStan
make pintf   # Pint (style PSR-12)
make test    # PHPUnit
```

Nommage des branches : `feat/`, `fix/`, `test/`, `refactor/`

Format des commits : `feat: description`, `fix: description`, etc.

Pour les détails complets de chaque tâche (contexte, critères d'acceptation, pistes techniques), consulter les [issues GitHub](https://github.com/mzeahmed/jobscan/issues).
