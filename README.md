# JOBSCAN

[![CI](https://github.com/mzeahmed/jobscan/actions/workflows/ci.yml/badge.svg)](https://github.com/mzeahmed/jobscan/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)](https://github.com/mzeahmed/jobscan/blob/main/app/composer.json)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![Ollama](https://img.shields.io/badge/AI-Ollama%20%7C%20Gemini-1793D1)](https://ollama.com)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](#license)

Agrégateur d'opportunités tech (freelance ou CDI) orienté PHP / Symfony / WordPress, avec scoring IA local.

JOBSCAN récupère des offres depuis des providers configurés (flux RSS et recherche web dynamique), filtre les opportunités pertinentes, les analyse avec un **moteur LLM au choix** (Ollama, LM Studio ou Gemini — configurable via `DEFAULT_LLM_PROVIDER`), leur attribue un score de pertinence, puis déclenche une alerte pour les meilleures opportunités.

Fonctionne **100% gratuitement** : en local avec Ollama, ou avec un moteur IA cloud
(Gemini, et plus tard Claude/OpenAI) en profitant de leurs offres gratuites — aucune
dépendance payante n'est requise, mais le choix reste ouvert selon vos besoins.

---

## Architecture

```text
Providers (RSS + RemoteOK + SearXNG) → JobProcessor → DB (job + job_source, analyse « pending »)
                                                      ↓
                          app:jobs:analyze / fin de run : AIClient (Ollama, LM Studio, Gemini) → Scoring → Notifier (Telegram)
```

1. **Providers** : récupèrent les offres depuis des flux RSS, RemoteOK et/ou la recherche web via SearXNG
2. **Processor** : filtre, déduplique et **persiste l'offre avant toute analyse** — la collecte ne dépend jamais du LLM
3. **Persistance** : SQLite ; une offre (`job`) peut avoir plusieurs sources (`job_source`)
4. **Analyse IA** : reprise des offres en attente par `PendingAnalysisRunner` (fin de `app:jobs:run` ou `app:jobs:analyze`). `AIClient` délègue au moteur choisi par `DEFAULT_LLM_PROVIDER` et extrait des données structurées
5. **Scoring** : attribution d'un score /100 selon la stack, le remote, le type de contrat, l'urgence, etc.
6. **Notification** : alerte Telegram pour les meilleures opportunités, uniquement une fois l'analyse définitive

### Fiabilité de la collecte

**Statut d'analyse.** Chaque offre porte un statut : `pending` (jamais analysée), `done` (analysée par le LLM),
`fallback` (analyse heuristique, LLM indisponible — sera retentée) ou `failed` (erreur — sera retentée).
Les reprises suivent un backoff exponentiel (`app.profile.analysis_*` : 5 tentatives, 15 min initiales, plafond 24 h).
Une analyse heuristique n'empêche jamais une analyse LLM ultérieure. Une offre non analysée de façon définitive n'est pas
notifiée ; si le LLM reste indisponible jusqu'à épuisement des tentatives, l'analyse heuristique devient définitive.

**Dates.** Quatre notions distinctes : `publishedAt` (publication réelle, parfois inconnue), `firstSeenAt` (première détection
par Jobscan), `lastSeenAt` (dernière observation par un provider) et `analyzedAt`. `publicationDateSource` et
`publicationDateConfidence` (`high`, `medium`, `low`, `unknown`) qualifient la date de publication. Une date inconnue reste
inconnue, et une republication ne remplace jamais une date plus fiable ou plus ancienne à fiabilité égale.

| Provider | Date de publication | Confiance |
|---|---|---|
| RemoteOK | champ `date` de l'API | `high` |
| RSS / Atom | `pubDate` ou `published` | `medium` |
| RSS / Atom | `updated` seulement (n'est pas une date de publication) | `low` |
| SearXNG | `publishedDate` / `pubdate` | `medium` |
| SearXNG | date devinée dans un extrait ou un titre | `low` |

**Déduplication.** Un doublon est *certain* s'il partage une URL, une URL canonique ou un identifiant externe (même provider) :
l'offre existante reçoit une nouvelle source et une nouvelle observation. Un doublon est *probable* s'il partage seulement titre,
entreprise et lieu : l'offre est conservée, signalée (`probableDuplicateOfId`) et jamais notifiée, mais jamais fusionnée ni supprimée.
Les paramètres `ref` et `source` ne sont plus retirés des URLs canoniques : ils peuvent identifier la ressource.

**Restriction de collecte et exclusion métier.** `COLLECTION_BLOCKED_DOMAINS` empêche SearXNG et les flux RSS de récupérer des
URLs de ces domaines (plateformes interdisant l'extraction automatisée). `EXCLUDED_PLATFORM_DOMAINS` écarte une offre quelle
que soit sa provenance. Pour garder LinkedIn hors du scraping tout en acceptant ses alertes email futures, placez-le dans
`COLLECTION_BLOCKED_DOMAINS` et non dans `EXCLUDED_PLATFORM_DOMAINS`.

**Fraîcheur.** `FreshnessScorer` convertit l'ancienneté de la date de publication en score 0–100 selon le barème
`app.profile.freshness_*` (hypothèse métier configurable : < 6 h = 100, < 24 h = 90, < 48 h = 75, < 96 h = 60, < 7 j = 40,
< 14 j = 20, au-delà 5). Une date inconnue, de fiabilité inconnue ou située dans le futur reçoit `unknown_score` (25), jamais le
maximum ; une date de fiabilité faible est plafonnée (`low_confidence_cap`, 50). Ce score sera combiné à la compatibilité
dans une phase ultérieure.

**Monitoring.** Chaque exécution d'un provider est tracée (table `provider_run`) avec son statut — `succeeded`, `no_new_jobs`,
`partial`, `failed` ou `disabled` —, ses compteurs (récupérées, nouvelles, doublons, erreurs), sa durée et sa dernière erreur.
Un provider en échec n'empêche pas les autres de s'exécuter. `app:jobs:stats` affiche la dernière exécution et le dernier succès.

---

## Stack technique

* PHP 8.4.1+
* Symfony
* SQLite
* Ollama (moteur IA local recommandé, API compatible OpenAI) ou Gemini (alternative cloud)
* SearXNG (moteur de recherche open-source local)
* Telegram Bot API (notifications)
* Docker

---

## Prérequis

* PHP 8.4.1+ (contrainte de Symfony 8.1 ; voir [Troubleshooting](docs/TROUBLESHOOTING.md) si le `php` par défaut de la machine est plus ancien)
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
cp .env.example .env     # requis AVANT composer install (cache:clear lit .env)
composer install
php bin/console typescript:build   # assets TypeScript, requis pour rendre l'interface web et lancer PHPUnit
```

`app/.env` et `app/.env.local` sont ignorés par Git : `.env.example` est le seul
modèle versionné. Copiez-le en `.env`, puis placez vos valeurs propres à la machine
(tokens, clés, URLs) dans `app/.env.local`, qui surcharge `.env`.

Initialiser la base de données :

```bash
make migrate
# ou, sans Symfony CLI :
php bin/console doctrine:migrations:migrate --no-interaction
```

---

## Configuration

### Variables d'environnement — `app/.env.local`

```dotenv
# Provider LLM actif
DEFAULT_LLM_PROVIDER=lmstudio

# Ollama (recommandé)
OLLAMA_BASE_URL=http://localhost:11434/v1
OLLAMA_MODEL=llama3.1:8b

# Si Symfony tourne dans Docker et Ollama sur l'hôte :
# OLLAMA_BASE_URL=http://host.docker.internal:11434/v1

# LM Studio (legacy)
LMSTUDIO_BASE_URL=http://localhost:1234/v1
LMSTUDIO_MODEL=local-model

# Gemini — clé sur https://aistudio.google.com/api-keys
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.0-flash

# Telegram
TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...

# SearXNG
SEARXNG_URL=http://localhost:8080      # hors Docker
#SEARXNG_URL=http://searxng:8080       # dans Docker

# Flux RSS (optionnel)
# JOB_FEED_URL_1=https://www.reddit.com/r/forhire/.rss
JOB_FEED_URL_2=
JOB_FEED_URL_3=

# RemoteOK (API JSON)
REMOTEOK_API_URL=https://remoteok.com/api

# Profil métier (voir "Mots-clés, requêtes et stack" plus bas)
FILTER_KEYWORDS=php,symfony,wordpress,backend,fullstack,api
KNOWN_STACK=php,symfony,wordpress,mysql,postgresql,redis,docker,react,vue,api
SEARX_QUERIES="php symfony remote job,php symfony freelance remote"
JOB_LOCATIONS="Paris,Remote"
# Domaines que SearXNG et les flux RSS ne doivent pas collecter (restriction de collecte)
COLLECTION_BLOCKED_DOMAINS=linkedin.com
# Domaines dont les offres sont écartées quelle que soit leur provenance (exclusion métier)
EXCLUDED_PLATFORM_DOMAINS=
```

> Le moteur LLM actif se choisit avec `DEFAULT_LLM_PROVIDER` (`ollama`, `lmstudio`
> ou `gemini`). Placez une surcharge propre à votre machine dans `.env.local`.

### Mots-clés, requêtes et stack — `app/.env`

Le profil métier (mots-clés, stack technique, requêtes SearXNG, localisations) est
configurable via des variables d'environnement — aucune modification de code PHP ni
de YAML n'est nécessaire pour adapter JOBSCAN à un autre profil technique.

```dotenv
FILTER_KEYWORDS=php,symfony,wordpress,backend,fullstack,api
KNOWN_STACK=php,symfony,wordpress,mysql,postgresql,redis,docker,react,vue,api,rabbitmq,laravel,typescript,javascript
SEARX_QUERIES="php symfony remote job,php symfony freelance remote,wordpress php remote developer,backend php api remote job,développeur php,mission freelance php symfony remote"
JOB_LOCATIONS="Marseille,Paris,Ile-de-France,Remote"
COLLECTION_BLOCKED_DOMAINS=linkedin.com,indeed.com
EXCLUDED_PLATFORM_DOMAINS=
```

`app/config/packages/jobscan.yaml` les expose comme paramètres de service via le
processeur d'environnement `csv` (`%env(csv:FILTER_KEYWORDS)%`, etc.), qui découpe
la chaîne sur les virgules — pas d'espace après la virgule, entourer de guillemets
toute valeur contenant elle-même un espace.

| Paramètre | Env var | Utilisé par | Rôle |
|---|---|---|---|
| `app.profile.filter_keywords` | `FILTER_KEYWORDS` | `JobProcessor` | Écarte les offres hors scope avant tout traitement IA |
| `app.profile.known_stack` | `KNOWN_STACK` | `AIClient` | Détecte la stack technique en fallback heuristique |
| `app.profile.searx_queries` | `SEARX_QUERIES` | `SearxProvider` | Requêtes envoyées à SearXNG à chaque run |
| `app.profile.job_locations` | `JOB_LOCATIONS` | `SearxProvider` | Localisations combinées aux requêtes non localisées |
| `app.profile.excluded_platform_domains` | `EXCLUDED_PLATFORM_DOMAINS` | `JobProcessor` | Exclusion métier : écarte les offres de ces domaines (sous-domaines inclus), quelle que soit leur provenance |
| `app.profile.collection_blocked_domains` | `COLLECTION_BLOCKED_DOMAINS` | `SearxProvider`, `RsFeedProvider` | Restriction de collecte : ces providers ne récupèrent pas les URLs de ces domaines |
| `app.profile.analysis_max_attempts` | — (YAML) | `AnalysisRetryPolicy` | Tentatives d'analyse maximum par offre |
| `app.profile.analysis_initial_delay_minutes` / `_retry_multiplier` / `_max_delay_minutes` | — (YAML) | `AnalysisRetryPolicy` | Backoff des reprises d'analyse |
| `app.profile.analysis_batch_limit` | — (YAML) | `app:jobs:run`, `app:jobs:analyze` | Offres analysées par exécution |
| `app.profile.freshness_bands` / `_older_score` / `_unknown_score` / `_low_confidence_cap` | — (YAML) | `FreshnessScorer` | Barème de fraîcheur |
| `app.profile.searx_max_pages` | — (reste en YAML) | `SearxProvider` | Nombre maximal de pages récupérées par requête |
| `app.profile.searx_query_delay_ms` | — (reste en YAML) | `SearxProvider` | Délai entre les lots concurrents (`0` le désactive) |
| `app.profile.searx_blocked_patterns` | — (reste en YAML) | `SearxNoiseFilter` | Patterns qui écartent un résultat de recherche (docs, tutoriels…) |
| `app.profile.searx_job_signals` | — (reste en YAML) | `SearxNoiseFilter` | Mots-clés attestant qu'un résultat est bien une offre |
| `app.profile.persistence_batch_size` | — (reste en YAML) | `JobProcessor` | Nombre d'offres persistées par lot Doctrine |
| `app.profile.ai_max_retries` | — (reste en YAML) | Clients LLM | Nombre de retries après l'appel initial |
| `app.profile.ai_initial_retry_delay_ms` | — (reste en YAML) | Clients LLM | Délai initial du backoff exponentiel |
| `app.profile.ai_retry_multiplier` | — (reste en YAML) | Clients LLM | Multiplicateur appliqué au délai de retry |
| `app.ai_system_prompt` | — (reste en YAML) | `AIClient` | Prompt système envoyé au provider IA |

Pour adapter JOBSCAN à un autre profil (ex : Python / Django, ou Java / Spring), il suffit d'ajuster ces variables dans `app/.env.local`.

Les pondérations, seuils, retries LLM et Telegram ainsi que les limites SearXNG sont
regroupés sous `app.profile.*` dans `config/packages/jobscan.yaml`. Les appels LLM
réessaient les erreurs transitoires au maximum deux fois avant le fallback. SearXNG met ses réponses en
cache chaque page, limite le nombre de requêtes et de pages par exécution, espace
les lots concurrents et n'ajoute pas une seconde localisation aux requêtes qui en
contiennent déjà une.

---

## Providers

JOBSCAN supporte plusieurs providers, chacun implémentant `JobProviderInterface`
(`name()`, `isHealthy()`, `fetch()`).

Au démarrage de `app:jobs:run`, chaque provider est sondé via `isHealthy()` : un
provider injoignable (ex. `SEARXNG_URL` mal configurée) est ignoré avec un
avertissement, sans annuler le run. L'option `--skip-health-check` désactive ce
contrôle (utile hors ligne ou en test).

### RsFeedProvider

Récupère les offres depuis des **flux RSS/Atom** configurés via les variables `JOB_FEED_URL_*`.
Reddit est désactivé par défaut, son endpoint public répondant régulièrement avec HTTP 403/429.

* Source identifiée automatiquement depuis le domaine du flux
* Dépend de la qualité et de la fraîcheur des flux fournis
* Fonctionne hors ligne si les URLs sont accessibles

### RemoteOkProvider

Récupère les offres depuis l'API JSON officielle de RemoteOK, configurable via
`REMOTEOK_API_URL`.

### SearxProvider

Effectue des **recherches web dynamiques** via SearXNG en lançant une série de requêtes ciblées (ex : `php symfony remote job`, `mission freelance php`).

* Résultats issus du web en temps réel
* Filtrage automatique des résultats non pertinents (documentations, tutoriels, etc.)
* Aucun coût d'API, aucune clé requise

### FranceTravailProvider

API « Offres d'emploi » de France Travail (OAuth2, collecte incrémentale, pagination, limitation de débit).
**Désactivé par défaut et non validé contre la documentation officielle** : l'accès est restreint et l'API est
signalée comme suspendue. Voir [docs/PROVIDERS.md](docs/PROVIDERS.md) avant toute activation.

### EmailJobProvider

Offres des emails d'alerte (LinkedIn, Indeed, HelloWork, Apec, France Travail, Welcome to the Jungle) reçus dans une boîte
IMAP dédiée. Un email n'est marqué traité qu'après ingestion réussie ; la boîte n'est jamais modifiée. **Désactivé par défaut,
parsers non validés sur de vrais emails** : voir [docs/EMAIL.md](docs/EMAIL.md).

### Greenhouse, Lever, Ashby, Recruitee, Workable, SmartRecruiters

Offres des job boards d'entreprises via les API publiques de Greenhouse, Lever, Ashby, Recruitee, Workable et SmartRecruiters, pour une liste de boards que vous
configurez (`GREENHOUSE_BOARDS`, `LEVER_BOARDS`, `ASHBY_BOARDS`, `RECRUITEE_BOARDS`, `WORKABLE_BOARDS`, `SMARTRECRUITERS_BOARDS`). Sans clé, sans scraping ; inactifs tant qu'aucun board n'est
configuré. Dates de publication inconnues pour Greenhouse et Lever. Voir [docs/PROVIDERS.md](docs/PROVIDERS.md).

Les providers sont complémentaires. Il est possible d'en ajouter d'autres en implémentant `JobProviderInterface`.

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

> **Note** : SearXNG doit avoir `format: json` activé dans `.docker/searxng/settings.yml` pour retourner des réponses JSON.

---

## Profil candidat et profils de recherche

Votre profil (compétences, expériences, préférences) et vos stratégies de recherche se décrivent dans deux fichiers YAML
**locaux**, ignorés par Git et exclus des images Docker :

```bash
php bin/console app:profile:init       # crée config/profile/local/candidate.yaml et search_profiles.yaml (exemples fictifs)
php bin/console app:profile:validate   # contrôle sans jamais afficher le contenu
```

Jobscan n'invente aucune compétence : seule ce que vous déclarez compte. Ce chargement est la première brique du
matching par profil ; il n'est pas encore branché sur le scoring. Voir [docs/PROFILE.md](docs/PROFILE.md).

---

## Matching par profil de recherche

Chaque offre analysée est appariée à vos profils de recherche : **compatibilité** (calculée en PHP, déterministe), **fraîcheur** et **priorité**
(75 % compatibilité, 25 % fraîcheur, la fraîcheur seule ne rend jamais prioritaire une offre incompatible). Une information inconnue n'est jamais
traitée comme une incompatibilité ; les critères éliminatoires (exclusions, contrat ou mode de travail explicitement hors préférences) sont évalués à part.

```bash
php bin/console app:jobs:priority        # offres compatibles par priorité, avec leur meilleur profil
php bin/console app:jobs:explain 123     # pourquoi cette offre : critères, compétences manquantes, arguments, CV recommandé
php bin/console app:jobs:match           # (re)calcule les résultats périmés, sans appel au LLM
```

Voir [docs/MATCHING.md](docs/MATCHING.md). Ce matching ne remplace pas encore le score historique, qui reste celui des notifications.

---

## Moteur d'analyse IA

JOBSCAN analyse les offres via un moteur LLM choisi par `DEFAULT_LLM_PROVIDER` :
`ollama` (provider local compatible OpenAI),
`lmstudio` (legacy, même famille qu'Ollama) ou `gemini` (API Google, cloud).

Le reste de l'application ne dépend que de `LLMClientInterface::analyze()` — jamais
d'un provider concret. La sélection est faite par `LLMClientFactory` — voir
`src/AI/Provider/` pour ajouter un futur provider (Claude, OpenAI, ...) sans toucher
à `AIClient` : créer une classe implémentant `LLMClientInterface`, la déclarer dans
`config/services.yaml`, et ajouter une entrée dans le `match()` de `LLMClientFactory`.

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

Le champ `id` retourné correspond à la valeur à utiliser dans `OLLAMA_MODEL`.

#### Configuration `.env.local`

```dotenv
DEFAULT_LLM_PROVIDER=ollama
OLLAMA_BASE_URL=http://localhost:11434/v1
OLLAMA_MODEL=llama3.1:8b
```

---

### LM Studio (legacy — conservé pour compatibilité, non recommandé)

LM Studio reste fonctionnel et peut être utilisé en remplacement d'Ollama sans aucune
modification du code.

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
DEFAULT_LLM_PROVIDER=lmstudio
LMSTUDIO_BASE_URL=http://localhost:1234/v1
LMSTUDIO_MODEL=local-model
```

---

### Gemini (alternative cloud)

Gemini est utile quand aucun modèle local n'est disponible (machine sans GPU, CI, etc.).

#### Obtenir une clé d'API

Créer une clé sur [Google AI Studio](https://aistudio.google.com/api-keys).

#### Configuration `.env.local`

```dotenv
DEFAULT_LLM_PROVIDER=gemini
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

Pour simuler un run sans écriture ni notification, ou limiter les sources :

```bash
php bin/console app:jobs:run --dry-run
php bin/console app:jobs:run --provider=remoteok --provider=searxng
php bin/console app:jobs:run --skip-health-check
php bin/console app:jobs:run --no-analyze   # collecte et persiste sans appeler le LLM
php bin/console app:jobs:stats
```

### Reprendre les analyses

Les offres persistées en attente, analysées par heuristique ou en échec sont reprises à la fin de `app:jobs:run`
(`app.profile.analysis_batch_limit`) et à la demande :

```bash
php bin/console app:jobs:analyze --limit=100
```

### Maintenance et export des offres

```bash
# Prévisualiser puis supprimer les offres ingérées il y a plus de 30 jours
php bin/console app:jobs:purge --older-than=30d --dry-run
php bin/console app:jobs:purge --older-than=4w

# Exporter les offres scorées (stdout par défaut)
php bin/console app:jobs:export --format=csv --min-score=60
php bin/console app:jobs:export --format=json --output=var/export.json
```

`--older-than` accepte `Nd` (jours) ou `Nw` (semaines). `app:jobs:export` produit
les colonnes `id,title,score,source,contract,remote,url,created_at`.

Pour vider uniquement les offres avant un test complet, sans toucher aux migrations :

```bash
make run-pipeline RESET=1
make run-pipeline RESET=1 PIPELINE_ARGS="--provider=remoteok"
```

Le reset est réservé aux environnements `dev` et `test`, et ne peut pas être combiné
avec `--dry-run`.

### Suivre les alertes en temps réel

```bash
make alerts
# ou
tail -f app/var/alerts.log
```

### Avec Docker

La stack Docker inclut Traefik en reverse proxy HTTPS, qui expose l'application et
SearXNG sur des domaines locaux (`jobscan.local`, `searxng.local`). Ouvrez toujours **`https://`** : le port `8443` est un port HTTPS (une URL `http://jobscan.local:8443` donne « 404 page not found »).

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

> Si Ollama tourne sur la machine hôte et Symfony dans Docker, `OLLAMA_BASE_URL` est automatiquement configuré sur `http://host.docker.internal:11434/v1` dans le `docker-compose.yml`.

Le conteneur de développement tourne sous **votre** identifiant (`HOST_UID`/`HOST_GID`, exportés par le `makefile`) : plus de fichiers appartenant à root dans `app/`.

**Déploiement autonome** (`docker-compose.prod.yml`) : code figé dans l'image, utilisateur sans privilège, planification (`cron`) et boutons Telegram en services optionnels, aucun secret dans les images. `make prod-up` / `make prod-up-workers` ; voir [docs/DEPLOY.md](docs/DEPLOY.md).

---

## Automatisation (Cron)

JOBSCAN peut tourner de manière entièrement autonome via un cron local (ou, dans Docker, via le service `cron` du [déploiement autonome](docs/DEPLOY.md)).

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

L'analyse est effectuée par `AIClient`, qui délègue l'appel au client sélectionné par
`DEFAULT_LLM_PROVIDER` (via `LLMClientFactory`) : `OllamaClient` ou `LMStudioClient`
(API compatible OpenAI, `POST {BASE_URL}/chat/completions`) ou `GeminiClient`
(API Gemini de Google).

L'IA extrait, **indépendamment de tout profil candidat**, les exigences structurées de l'annonce :

* compétences **exigées**, **appréciées** et simplement **citées**
* séniorité et années d'expérience demandées
* langues, mode de travail (sur site, hybride, remote)
* types de contrat (`freelance`, `cdi`, `cdd`, `alternance`, `stage`)
* rémunération annoncée (jamais convertie)
* lieux

Chaque information est **ancrée dans le texte de l'annonce** : ce que le modèle invente est écarté. Une information absente reste
inconnue, jamais « non ». L'annonce est traitée comme une donnée non fiable (consignes détectées et retirées, réponse validée).
Le score final reste calculé en PHP. Voir [docs/EXTRACTION.md](docs/EXTRACTION.md).

En cas d'échec de l'analyse IA, JOBSCAN utilise un **fallback heuristique** basé sur des règles locales. Les offres analysées avant
l'extraction structurée se mettent à jour avec `php bin/console app:jobs:analyze --outdated`.

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

Avec un profil candidat et un profil de recherche actif, les notifications suivent le **matching** : opportunité prioritaire (immédiate), offre intéressante
(selon configuration), offre secondaire (récapitulatif périodique, `app:notifications:digest`). Chaque message donne le poste, l'entreprise, le lieu, le contrat,
le salaire, le télétravail, la date de publication et l'ancienneté, les trois scores, le profil et le CV recommandés, les principales raisons et le lien — seulement
pour les informations disponibles. Une offre incompatible n'est jamais notifiée, même très fraîche, et aucune offre ne l'est deux fois. Sans profil, l'ancien mécanisme
(score historique, seuil de **60/100**) reste en vigueur. Voir [docs/NOTIFICATIONS.md](docs/NOTIFICATIONS.md). Les notifications portent des boutons (suivre, garder, ignorer, j'ai postulé) et le bot répond à `/offres` et `/candidatures` via `app:telegram:poll`, sans port exposé ; voir [docs/TELEGRAM.md](docs/TELEGRAM.md). `app:reminders:send` rappelle par Telegram les relances que vous avez planifiées, une seule fois par date ; voir [docs/REMINDERS.md](docs/REMINDERS.md).

---

## Suivi des candidatures

`app:applications:track|status|list` consignent vos candidatures (statut, dates, CV utilisé, recruteur, message envoyé, relance) et `app:jobs:decide` sauvegarde, ignore ou archive une
offre. Jobscan n'envoie rien : il enregistre ce que vous déclarez. Ces offres échappent à la purge. Voir [docs/APPLICATIONS.md](docs/APPLICATIONS.md).

L'**interface web** (offres et candidatures) est protégée par un compte unique, un jeton CSRF sur chaque écriture et une limitation des tentatives ; elle reste fermée tant que `app:ui:hash-password` n'a pas été utilisé. Voir [docs/UI.md](docs/UI.md).

`app:analytics:report` et la page **Analytics** montrent, sur vos seules données, ce qui fonctionne : réponses par source, CV, profil et canal, rendement des sources. Des comptages, pas des preuves (pourcentage à partir de 5 candidatures) ; voir [docs/ANALYTICS.md](docs/ANALYTICS.md).

`app:health:check` contrôle l'installation (migrations, sources, analyses, notifications, rappels, disque, profils) et prévient par Telegram une fois par changement d'état ; `app:report:weekly` produit le rapport de période. Voir [docs/MONITORING.md](docs/MONITORING.md).

`app:cv:list` et `app:cv:recommend` gèrent vos versions de CV (fichiers locaux, jamais lus ni transmis) ; voir [docs/CV.md](docs/CV.md).

`app:assistant:draft` prépare un brouillon de message de candidature à partir de vos seuls faits déclarés (rien n'est envoyé ni enregistré ; LLM optionnel, local par défaut) ; voir [docs/ASSISTANT.md](docs/ASSISTANT.md).

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
* **RsFeedProvider**, **RemoteOkProvider** et **SearxProvider** sont actifs dans le pipeline
* le pipeline fonctionne même sans IA grâce au fallback heuristique
* aucune dépendance externe payante (pas d'API tierce, pas de clé requise)

---

## Contribuer

Les contributions sont les bienvenues. Consultez [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md)
pour le guide complet, [docs/ROADMAP.md](docs/ROADMAP.md) pour les chantiers ouverts,
[docs/CHANGELOG.md](docs/CHANGELOG.md) pour les évolutions et
[docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) en cas de problème avec la stack
Docker ou l'exécution locale.

**En bref :**

```bash
make setup        # configure les git hooks (.githooks/)
cd app
cp .env.example .env
composer install
php bin/console typescript:build
cd ..
make migrate
```

### Hooks de qualité (pre-commit)

Ce projet utilise [pre-commit](https://blog.stephane-robert.info/docs/outils/qualite-code/pre-commit/) pour automatiser les vérifications avant chaque commit et push. `make setup` configure le répertoire de hooks ; pre-commit doit être installé séparément (voir [Prérequis](#prérequis)).

| Moment | Vérifications |
|---|---|
| `git commit` | trailing whitespace, YAML/JSON, secrets (gitleaks), PHP-CS-Fixer, PHPStan |
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
