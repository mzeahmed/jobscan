# JOBSCAN

Agrégateur d'opportunités tech (freelance ou CDI) orienté PHP / Symfony / WordPress, avec scoring IA local.

JOBSCAN récupère des offres depuis des providers configurés (flux RSS et recherche web dynamique), filtre les opportunités pertinentes, les analyse avec **LM Studio** via son API compatible OpenAI, leur attribue un score de pertinence, puis déclenche une alerte pour les meilleures opportunités.

Fonctionne **100% en local**, sans aucune dépendance externe payante.

---

## Architecture

```text
Providers (RSS + SearXNG) → JobProcessor → AIClient (LM Studio) → ScoringService → DB → Notification
```

1. **Providers** : récupèrent les offres depuis des flux RSS et/ou la recherche web via SearXNG
2. **Processor** : filtre les doublons et les offres hors scope
3. **Analyse IA** : LM Studio analyse l'offre et extrait des données structurées
4. **Scoring** : attribution d'un score /100 selon la stack, le remote, le type de contrat, l'urgence, etc.
5. **Persistance** : sauvegarde en base SQLite
6. **Notification** : envoi d'une alerte Telegram pour les meilleures opportunités

---

## Stack technique

* PHP 8.3+
* Symfony
* SQLite
* LM Studio (analyse IA locale, API compatible OpenAI)
* SearXNG (moteur de recherche open-source local)
* Telegram Bot API (notifications)
* Docker

---

## Prérequis

* PHP 8.3+
* Composer
* Symfony CLI
* SQLite
* Docker
* **LM Studio** installé localement

---

## Installation

```bash
git clone https://github.com/mzeahmed/jobscan.git
cd jobscan
composer install
cp .env .env.local
```

Initialiser la base de données :

```bash
make migrate
```

---

## Configuration

### Variables d'environnement — `.env.local`

```dotenv
# LM Studio (IA locale)
AI_API_BASE=http://localhost:1234/v1
AI_API_KEY=lmstudio
AI_MODEL=local-model

# Telegram
TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...

# SearXNG
SEARXNG_URL=http://localhost:8080      # hors Docker
#SEARXNG_URL=http://searxng:8080       # dans Docker

# Flux RSS (optionnel)
JOB_FEED_URL_1=
JOB_FEED_URL_2=
JOB_FEED_URL_3=
```

### Mots-clés, requêtes et stack — `config/packages/jobscan.yaml`

Toute la configuration métier est centralisée dans un seul fichier YAML. Aucune modification de code PHP n'est nécessaire pour adapter JOBSCAN à un autre profil technique.

```yaml
parameters:
    app.filter_keywords:       # filtre d'entrée du pipeline
        - php
        - symfony
        - wordpress
        - backend
        - fullstack
        - api

    app.known_stack:           # technos reconnues par le fallback heuristique
        - php
        - symfony
        - wordpress
        - mysql
        - postgresql
        - redis
        - docker
        - react
        - vue
        - api
        - rabbitmq
        - laravel
        - typescript
        - javascript

    app.searx_queries:         # requêtes envoyées à SearXNG
        - 'php symfony remote job'
        - 'php symfony freelance remote'
        - 'wordpress php remote developer'
        - 'backend php api remote job'
        - 'développeur php symfony full remote'
        - 'développeur php WordPress'
        - 'mission freelance php symfony remote'

    app.ai_system_prompt: |   # prompt système envoyé à LM Studio
        ...
```

| Paramètre | Utilisé par | Rôle |
|---|---|---|
| `app.filter_keywords` | `JobProcessor` | Écarte les offres hors scope avant tout traitement IA |
| `app.known_stack` | `AIClient` | Détecte la stack technique en fallback heuristique |
| `app.searx_queries` | `SearxProvider` | Requêtes envoyées à SearXNG à chaque run |
| `app.ai_system_prompt` | `AIClient` | Prompt système envoyé à LM Studio |

Pour adapter JOBSCAN à un autre profil (ex : Python / Django, ou Java / Spring), il suffit de modifier ce fichier.

---

## Providers

JOBSCAN supporte plusieurs providers, chacun implémentant `JobProviderInterface`.

### RsFeedProvider

Récupère les offres depuis des **flux RSS/Atom** configurés via les variables `JOB_FEED_URL_*`.

* Source statique, passive
* Dépend de la qualité et de la fraîcheur des flux fournis
* Fonctionne hors ligne si les URLs sont accessibles

### SearxProvider

Effectue des **recherches web dynamiques** via SearXNG en lançant une série de requêtes ciblées (ex : `php symfony remote job`, `mission freelance php`).

* Résultats issus du web en temps réel
* Filtrage automatique des résultats non pertinents (documentations, tutoriels, etc.)
* Aucun coût d'API, aucune clé requise

Les deux providers sont complémentaires. Il est possible d'en ajouter d'autres en implémentant `JobProviderInterface`.

---

## SearXNG

[SearXNG](https://github.com/searxng/searxng) est un méta-moteur de recherche open-source, auto-hébergé, qui agrège les résultats de plusieurs moteurs (Google, Bing, DuckDuckGo, etc.) sans tracking ni coût d'API.

JOBSCAN l'utilise comme moteur de recherche d'offres d'emploi en remplacement de tout service tiers payant.

**Pourquoi SearXNG ?**

* Gratuit, open-source, sans clé API
* Hébergé localement → aucune fuite de données
* Résultats web en temps réel
* Compatible JSON natif

### Installation SearXNG (Docker)

SearXNG est inclus dans le `docker-compose.yml` du projet :

```yaml
searxng:
    image: searxng/searxng
    container_name: jobscan_searxng
    ports:
        - "8080:8080"
    volumes:
        - ./.docker/searxng/settings.yml:/etc/searxng/settings.yml
    environment:
        SEARXNG_BASE_URL: http://localhost:8080
```

Le conteneur `app` y accède via le réseau Docker interne :

```dotenv
SEARXNG_URL=http://searxng:8080
```

### Vérifier que SearXNG fonctionne

```bash
curl "http://localhost:8080/search?q=php+symfony+remote&format=json"
```

Une réponse JSON contenant un tableau `results` confirme que SearXNG est opérationnel.

> **Note** : SearXNG doit avoir `format: json` activé dans `.docker/searxng/settings.yml` pour retourner des réponses JSON.

---

## LM Studio

JOBSCAN utilise **LM Studio** pour l'analyse locale des offres via son API compatible OpenAI.

### Installer LM Studio

Télécharge et installe LM Studio depuis le site officiel, ou via le paquet `.deb` sur Linux :

```bash
sudo apt install ./LM-Studio-0.4.12-1-x64.deb
```

### Lancer LM Studio

```bash
lm-studio
```

### Démarrer le serveur API local

```bash
lms server start
```

Le serveur écoute sur `http://localhost:1234` par défaut.

### Vérifier le serveur

```bash
curl http://localhost:1234/v1/models
```

Le champ `id` retourné doit être utilisé dans `AI_MODEL`.

---

## Utilisation

### Lancer le pipeline manuellement

```bash
make run-pipeline
# ou
php bin/console app:jobs:run
```

### Suivre les alertes en temps réel

```bash
make alerts
# ou
tail -f var/alerts.log
```

### Avec Docker

```bash
make up
make run-pipeline
make alerts
```

> Si LM Studio tourne sur la machine hôte et Symfony dans Docker, `AI_API_BASE` est automatiquement configuré sur `http://host.docker.internal:1234/v1` dans le `docker-compose.yml`.

---

## Automatisation (Cron)

JOBSCAN peut tourner de manière entièrement autonome via un cron local.

```bash
crontab -e
```

#### Toutes les 30 minutes

```bash
*/30 * * * * cd /home/USER/chemin/vers/jobscan && php bin/console app:jobs:run >> var/cron.log 2>&1
```

#### Version avec lock (recommandée)

Évite les exécutions simultanées si le pipeline est long :

```bash
*/30 * * * * cd /home/USER/chemin/vers/jobscan && flock -n /tmp/jobscan.lock php bin/console app:jobs:run >> var/cron.log 2>&1
```

### Logs cron

```bash
tail -f var/cron.log
```

### Vérifier que le cron tourne

```bash
systemctl status cron
```

> Adapter le chemin du projet et s'assurer que PHP est dans le `PATH` (`which php`). LM Studio et SearXNG doivent être démarrés pour que le pipeline complet fonctionne.

---

## Analyse IA

L'analyse est effectuée par `AIClient`, qui pointe vers **LM Studio**.

L'IA extrait notamment :

* stack technique
* type de contrat (`freelance`, `cdi`, `unknown`)
* remote
* budget
* récence
* seniority

En cas d'échec de l'analyse IA, JOBSCAN utilise un **fallback heuristique** basé sur des règles locales.

---

## Scoring

| Critère                 | Points |
| ----------------------- | -----: |
| PHP dans le titre       |    +20 |
| Symfony dans la stack   |    +30 |
| WordPress dans la stack |    +15 |
| Freelance               |    +20 |
| CDI                     |    +15 |
| Remote                  |    +10 |
| Offre récente           |    +20 |
| Mention mission         |    +10 |
| Urgent / ASAP           |    +15 |
| Stage                   |    -50 |
| Alternance              |    -50 |

Une notification est déclenchée à partir de **60/100**.

---

## Notifications Telegram

Variables à configurer :

```dotenv
TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...
```

---

## Commandes utiles

```bash
make help          # liste toutes les commandes
make migrate       # applique les migrations
make run-pipeline  # lance le pipeline
make alerts        # suit les alertes en live
make fix-perms     # corrige les permissions SQLite
make logs          # affiche les logs Docker
make bash          # ouvre un shell dans le conteneur app
```

---

## Base de données

SQLite :

*  `var/jobscan.db`

```bash
sqlite3 var/jobscan.db "SELECT id, title, score, source FROM job ORDER BY score DESC;"
```

---

## État actuel du projet

* **LM Studio** est utilisé pour l'analyse IA locale
* **SearXNG** est le moteur de recherche d'offres web
* **RsFeedProvider** et **SearxProvider** sont actifs dans le pipeline
* le pipeline fonctionne même sans IA grâce au fallback heuristique
* aucune dépendance externe payante (pas d'API tierce, pas de clé requise)

---

## Contribuer

Les contributions sont les bienvenues. Consultez [CONTRIBUTING.md](CONTRIBUTING.md) pour le guide complet et [ROADMAP.md](ROADMAP.md) pour les chantiers ouverts.

**En bref :**

```bash
make setup        # configure les git hooks
composer install
cp .env .env.local
make migrate
```

Avant chaque push : PHPStan et Pint s'exécutent via le hook `pre-push`, et si ça ne passe pas le push est bloqué. Assurez-vous de corriger les erreurs avant de pousser.

---

## Objectif

JOBSCAN n'est pas un job board.

C'est un filtre intelligent qui transforme un flux d'offres brutes en opportunités réellement exploitables — entièrement en local, sans aucun service externe payant.

```text
SearXNG (search) + RSS (feed) → Symfony (pipeline) → LM Studio (IA) → Score → Telegram
```
