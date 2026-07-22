# JOBSCAN

[![CI](https://github.com/mzeahmed/jobscan/actions/workflows/ci.yml/badge.svg)](https://github.com/mzeahmed/jobscan/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)](https://github.com/mzeahmed/jobscan/blob/main/app/composer.json)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![Ollama](https://img.shields.io/badge/AI-Ollama%20%7C%20Gemini-1793D1)](https://ollama.com)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](#license)

Agrégateur d'opportunités tech (freelance ou CDI) orienté PHP / Symfony / WordPress, avec scoring IA local.

JOBSCAN récupère des offres depuis des providers configurés (flux RSS et recherche web dynamique), filtre les opportunités pertinentes, les analyse avec un **moteur IA au choix** (Ollama par défaut, ou Gemini — configurable via `AI_PROVIDER` dans `.env`), leur attribue un score de pertinence, puis déclenche une alerte pour les meilleures opportunités.

Fonctionne **100% gratuitement** : en local avec Ollama, ou avec un moteur IA cloud
(Gemini, et plus tard Claude/OpenAI) en profitant de leurs offres gratuites — aucune
dépendance payante n'est requise, mais le choix reste ouvert selon vos besoins.

---

## Architecture

```text
Providers (RSS + SearXNG) → JobProcessor → AIClient (Ollama ou Gemini) → ScoringService → DB → Notification
```

1. **Providers** : récupèrent les offres depuis des flux RSS et/ou la recherche web via SearXNG
2. **Processor** : filtre les doublons et les offres hors scope
3. **Analyse IA** : `AIClient` délègue l'appel au moteur sélectionné par `AI_PROVIDER` (Ollama/LM Studio en local, ou Gemini) et extrait des données structurées
4. **Scoring** : attribution d'un score /100 selon la stack, le remote, le type de contrat, l'urgence, etc.
5. **Persistance** : sauvegarde en base SQLite
6. **Notification** : envoi d'une alerte Telegram pour les meilleures opportunités

---

## Stack technique

* PHP 8.3+
* Symfony
* SQLite
* Ollama (moteur IA local recommandé, API compatible OpenAI) ou Gemini (alternative cloud)
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
* **mkcert** (certificats HTTPS locaux — requis uniquement pour `make up`)
* **Ollama** installé localement (moteur IA recommandé), ou une clé **Gemini** en alternative cloud
* **pipx** + **pre-commit** (qualité de code — voir [guide pre-commit](https://blog.stephane-robert.info/docs/outils/qualite-code/pre-commit/))

```bash
# Ubuntu/Debian
sudo apt install pipx
pipx ensurepath
pipx install pre-commit
```

---

## Installation

```bash
git clone https://github.com/mzeahmed/jobscan.git
cd jobscan/app
composer install
cp .env .env.local
```

Initialiser la base de données :

```bash
make migrate
```

---

## Configuration

### Variables d'environnement — `app/.env.local`

```dotenv
# Moteur d'analyse IA actif : "ollama" (ou "lmstudio") ou "gemini"
AI_PROVIDER=ollama

# Provider IA — Ollama (défaut recommandé)
AI_API_BASE=http://localhost:11434/v1
AI_API_KEY=ollama
AI_MODEL=llama3.1:8b

# Si Symfony tourne dans Docker et Ollama sur l'hôte :
# AI_API_BASE=http://host.docker.internal:11434/v1

# Gemini (si AI_PROVIDER=gemini) — clé sur https://aistudio.google.com/api-keys
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.0-flash

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

# Profil métier (voir "Mots-clés, requêtes et stack" plus bas)
FILTER_KEYWORDS=php,symfony,wordpress,backend,fullstack,api
KNOWN_STACK=php,symfony,wordpress,mysql,postgresql,redis,docker,react,vue,api
SEARX_QUERIES="php symfony remote job,php symfony freelance remote"
JOB_LOCATIONS="Paris,Remote"
```

### Mots-clés, requêtes et stack — `app/.env`

Le profil métier (mots-clés, stack technique, requêtes SearXNG, localisations) est
configurable via des variables d'environnement — aucune modification de code PHP ni
de YAML n'est nécessaire pour adapter JOBSCAN à un autre profil technique.

```dotenv
FILTER_KEYWORDS=php,symfony,wordpress,backend,fullstack,api
KNOWN_STACK=php,symfony,wordpress,mysql,postgresql,redis,docker,react,vue,api,rabbitmq,laravel,typescript,javascript
SEARX_QUERIES="php symfony remote job,php symfony freelance remote,wordpress php remote developer,backend php api remote job,développeur php,mission freelance php symfony remote"
JOB_LOCATIONS="Marseille,Paris,Ile-de-France,Remote"
```

`app/config/packages/jobscan.yaml` les expose comme paramètres de service via le
processeur d'environnement `csv` (`%env(csv:FILTER_KEYWORDS)%`, etc.), qui découpe
la chaîne sur les virgules — pas d'espace après la virgule, entourer de guillemets
toute valeur contenant elle-même un espace.

| Paramètre | Env var | Utilisé par | Rôle |
|---|---|---|---|
| `app.filter_keywords` | `FILTER_KEYWORDS` | `JobProcessor` | Écarte les offres hors scope avant tout traitement IA |
| `app.known_stack` | `KNOWN_STACK` | `AIClient` | Détecte la stack technique en fallback heuristique |
| `app.searx_queries` | `SEARX_QUERIES` | `SearxProvider` | Requêtes envoyées à SearXNG à chaque run |
| `app.job_locations` | `JOB_LOCATIONS` | `SearxProvider` | Localisations combinées à chaque requête |
| `app.ai_system_prompt` | — (reste en YAML) | `AIClient` | Prompt système envoyé au provider IA |

Pour adapter JOBSCAN à un autre profil (ex : Python / Django, ou Java / Spring), il suffit d'ajuster ces variables dans `app/.env.local`.

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

JOBSCAN l'utilise comme moteur de recherche d'offres d'emploi, en alternative gratuite aux API de recherche tierces payantes.

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
    volumes:
        - ./docker/searxng/settings.yml:/etc/searxng/settings.yml
    environment:
        SEARXNG_BASE_URL: http://searxng.local
```

Aucun port n'est publié sur l'hôte : SearXNG est exposé via Traefik (`https://searxng.local:8443`,
voir [Domaines locaux](#avec-docker)) et le conteneur `app` y accède directement via le réseau
Docker interne :

```dotenv
SEARXNG_URL=http://searxng:8080
```

### Vérifier que SearXNG fonctionne

```bash
curl "https://searxng.local:8443/search?q=php+symfony+remote&format=json"
```

Une réponse JSON contenant un tableau `results` confirme que SearXNG est opérationnel.

> **Note** : SearXNG doit avoir `format: json` activé dans `docker/searxng/settings.yml` pour retourner des réponses JSON.

---

## Moteur d'analyse IA

JOBSCAN analyse les offres via un moteur IA choisi par la variable `AI_PROVIDER`
(`.env`) : `ollama` (défaut, provider local compatible OpenAI), `lmstudio` (legacy,
même famille qu'Ollama) ou `gemini` (API Google, cloud). La sélection est faite par
`AIProviderFactory` — voir `src/Service/AI/Provider/` pour ajouter un futur provider
(Claude, OpenAI, ...) sans toucher à `AIClient`.

### Ollama (recommandé)

#### Installer Ollama

```bash
curl -fsSL https://ollama.com/install.sh | sh
```

#### Lancer Ollama

```bash
ollama serve
```

#### Télécharger un modèle

```bash
ollama pull llama3.1:8b
```

#### Vérifier l'API

```bash
curl http://localhost:11434/v1/models
```

Le champ `id` retourné correspond à la valeur à utiliser dans `AI_MODEL`.

#### Configuration `.env.local`

```dotenv
AI_API_BASE=http://localhost:11434/v1
AI_API_KEY=ollama
AI_MODEL=llama3.1:8b
```

---

### LM Studio (legacy — conservé pour compatibilité, non recommandé)

LM Studio reste fonctionnel mais n'est plus le provider par défaut. Il peut être utilisé
en remplacement d'Ollama sans aucune modification du code.

#### Installer et démarrer LM Studio

Télécharge LM Studio depuis le site officiel, ou via le paquet `.deb` sur Linux :

```bash
sudo apt install ./LM-Studio-0.4.12-1-x64.deb
lm-studio
lms server start
```

Le serveur écoute sur `http://localhost:1234` par défaut.

#### Vérifier le serveur

```bash
curl http://localhost:1234/v1/models
```

#### Configuration `.env.local`

```dotenv
AI_API_BASE=http://localhost:1234/v1
AI_API_KEY=lmstudio
AI_MODEL=local-model
```

---

### Gemini (alternative cloud)

Gemini est utile quand aucun modèle local n'est disponible (machine sans GPU, CI, etc.).

#### Obtenir une clé d'API

Créer une clé sur [Google AI Studio](https://aistudio.google.com/api-keys).

#### Configuration `.env.local`

```dotenv
AI_PROVIDER=gemini
GEMINI_API_KEY=votre-clé
GEMINI_MODEL=gemini-2.0-flash
```

---

## Utilisation

### Lancer le pipeline manuellement

```bash
make run-pipeline
# ou
cd app && php bin/console app:jobs:run
```

### Suivre les alertes en temps réel

```bash
make alerts
# ou
tail -f app/var/alerts.log
```

### Avec Docker

La stack Docker inclut Traefik en reverse proxy HTTPS, qui expose l'application et
SearXNG sur des domaines locaux (`jobscan.local`, `searxng.local`).

> **Pourquoi `:8443` dans les URLs ?**
> Traefik écoute sur les ports hôte `8443` (HTTPS) et `9080` (dashboard) plutôt que
> `443`/`8080`. Le port standard `443` est souvent déjà occupé par le Traefik d'un
> autre projet local (chaque projet Docker faisant tourner son propre Traefik) — un
> navigateur assumant toujours le port par défaut (`443` pour HTTPS) quand il est
> omis, il faut l'expliciter tant que ce port reste pris ailleurs.
>
> Pour retirer le suffixe (si le port `443` est libre sur la machine, ou si un seul
> projet Docker tourne à la fois) :
>
> 1. Dans `docker-compose.yml`, remplacer `"8443:443"` par `"443:443"` (et
>    `"9080:8080"` par `"8080:8080"` si `8080` est libre aussi).
> 2. Les URLs redeviennent `https://jobscan.local` et `https://searxng.local`,
>    sans rien changer côté `dynamic.yml`/`traefik.yml` (le port hôte est
>    uniquement une question de mapping Docker, pas de configuration Traefik).

#### Premier lancement

```bash
mkcert -install
mkdir -p certs && cd certs && mkcert jobscan.local searxng.local && cd ..

make hosts   # ajoute jobscan.local et searxng.local dans /etc/hosts (sudo)
make up
make fix-perms     # requis : php-fpm tourne en www-data, différent de l'utilisateur hôte
make run-pipeline
make alerts
```

> **`Unable to write in the "cache" directory` dans le navigateur ?** Le conteneur
> `app` exécute php-fpm sous l'utilisateur `www-data`, qui n'a pas les droits
> d'écriture sur `app/var/` monté depuis l'hôte (appartenant à votre utilisateur local).
> `make fix-perms` corrige ça (`sudo chmod -R 777 app/var` — suffisant en local, à ne
> jamais faire en production).

Accès application (vue HTML des offres) :

* `https://jobscan.local:8443/job`

Accès SearXNG :

* `https://searxng.local:8443`

Dashboard Traefik :

* `http://localhost:9080`

> Si Ollama tourne sur la machine hôte et Symfony dans Docker, `AI_API_BASE` est automatiquement configuré sur `http://host.docker.internal:11434/v1` dans le `docker-compose.yml`.

---

## Automatisation (Cron)

JOBSCAN peut tourner de manière entièrement autonome via un cron local.

```bash
crontab -e
```

#### Toutes les 30 minutes

```bash
*/30 * * * * cd /home/USER/chemin/vers/jobscan/app && php bin/console app:jobs:run >> var/cron.log 2>&1
```

#### Version avec lock (recommandée)

Évite les exécutions simultanées si le pipeline est long :

```bash
*/30 * * * * cd /home/USER/chemin/vers/jobscan/app && flock -n /tmp/jobscan.lock php bin/console app:jobs:run >> var/cron.log 2>&1
```

### Logs cron

```bash
tail -f app/var/cron.log
```

### Vérifier que le cron tourne

```bash
systemctl status cron
```

> Adapter le chemin du projet et s'assurer que PHP est dans le `PATH` (`which php`). Ollama (ou LM Studio en mode legacy) et SearXNG doivent être démarrés pour que le pipeline complet fonctionne.

---

## Analyse IA

L'analyse est effectuée par `AIClient`, qui délègue l'appel au moteur sélectionné via
`AI_PROVIDER` : `OpenAICompatibleProvider` (`POST {AI_API_BASE}/chat/completions` —
**Ollama** par défaut, LM Studio en legacy) ou `GeminiProvider` (API Gemini de Google).

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
make cs            # PHP-CS-Fixer — vérification
make csf           # PHP-CS-Fixer — correction automatique
make rector-check  # Rector — vérification
make rector        # Rector — application
make stan          # PHPStan — analyse statique
```

---

## Base de données

SQLite :

* `app/var/jobscan.db`

```bash
sqlite3 app/var/jobscan.db "SELECT id, title, score, source FROM job ORDER BY score DESC;"
```

---

## État actuel du projet

* **Ollama** est le provider IA recommandé par défaut (LM Studio conservé en legacy)
* **SearXNG** est le moteur de recherche d'offres web
* **RsFeedProvider** et **SearxProvider** sont actifs dans le pipeline
* le pipeline fonctionne même sans IA grâce au fallback heuristique
* aucune dépendance externe payante (pas d'API tierce, pas de clé requise)

---

## Contribuer

Les contributions sont les bienvenues. Consultez [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md) pour le guide complet, [docs/ROADMAP.md](docs/ROADMAP.md) pour les chantiers ouverts, et [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) en cas de problème avec la stack Docker.

**En bref :**

```bash
make setup        # configure les git hooks (.githooks/)
cd app
composer install
cp .env .env.local
cd ..
make migrate
```

### Hooks de qualité (pre-commit)

Ce projet utilise [pre-commit](https://blog.stephane-robert.info/docs/outils/qualite-code/pre-commit/) pour automatiser les vérifications avant chaque commit et push. `make setup` configure le répertoire de hooks ; pre-commit doit être installé séparément (voir [Prérequis](#prérequis)).

| Moment | Vérifications |
|---|---|
| `git commit` | trailing whitespace, YAML/JSON, secrets (gitleaks), yamllint, markdownlint, PHP-CS-Fixer, PHPStan |
| `git push` | build assets TypeScript + AssetMapper, PHPUnit |

Pour contourner ponctuellement un hook : `git commit --no-verify` (à éviter).

---

## Objectif

JOBSCAN n'est pas un job board.

C'est un filtre intelligent qui transforme un flux d'offres brutes en opportunités réellement exploitables — entièrement en local, sans aucun service externe payant.

```text
SearXNG (search) + RSS (feed) → Symfony (pipeline) → Ollama (IA) → Score → Telegram
```

---

## License

MIT
