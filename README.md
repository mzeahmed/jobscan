# JOBSCAN

Agrégateur de missions freelance ou poste CDI PHP/Symfony avec scoring IA.

JOBSCAN interroge des sources d'offres (via Perplexity AI), filtre les missions pertinentes, leur attribue un score de pertinence et déclenche une alerte pour les meilleures opportunités.

---

## Fonctionnement

```
Provider (Perplexity) → JobProcessor → ScoringService → DB → Notification
```

1. **Provider** — récupère les offres depuis Perplexity AI (ou simulation si pas de clé)
2. **Processor** — filtre les doublons et les offres hors-scope (stage, alternance, non-PHP)
3. **Scoring** — attribue un score /100 selon la stack, le remote, l'urgence, etc.
4. **Persistance** — sauvegarde en base SQLite
5. **Notification** — alerte console + `var/alerts.log` si score ≥ 70/100

---

## Prérequis

- PHP 8.3+
- Composer
- Symfony CLI
- Docker (optionnel)

---

## Installation

```bash
git clone <repo>
cd jobscan
composer install
cp .env .env.local
```

Configurer `.env.local` :

```dotenv
PERPLEXITY_API_KEY=your_key_here   # optionnel, simulation si absent
TELEGRAM_BOT_TOKEN=...             # optionnel
TELEGRAM_CHAT_ID=...               # optionnel
```

Initialiser la base de données :

```bash
make migrate
```

---

## Utilisation

### Lancer le pipeline manuellement

```bash
make run-pipeline
# ou
php bin/console app:jobs:run
```

### Voir les alertes en temps réel

```bash
make alerts
# tail -f var/alerts.log
```

### Avec Docker

```bash
make up          # build + démarrage (dev)
make run-pipeline
make alerts
```

---

## Scoring

| Critère | Points |
|---|---|
| PHP dans le titre | +20 |
| Symfony dans la stack | +30 |
| WordPress dans la stack | +20 |
| Remote | +10 |
| Offre récente | +20 |
| Mention "senior" | +10 |
| Mention "mission" | +10 |
| Urgent / ASAP | +15 |
| Stage | -50 |
| Alternance | -50 |
| Junior | -20 |

Une notification est déclenchée à partir de **70/100**.

---

## Commandes utiles

```bash
make help          # liste toutes les commandes
make migrate       # applique les migrations
make run-pipeline  # lance le pipeline
make alerts        # suit les alertes en live
make fix-perms     # corrige les permissions SQLite
make pintf         # formate le code (Pint)
```

---

## Base de données

SQLite, fichier par environnement :

- dev  → `var/data_dev.db`
- prod → `var/data_prod.db`

Pour inspecter :

```bash
sqlite3 var/data_dev.db "SELECT id, title, score, source FROM job ORDER BY score DESC;"
```
