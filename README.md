# JOBSCAN

Agrégateur d'opportunités tech (freelance ou CDI) orienté PHP / Symfony / WordPress, avec scoring IA local.

JOBSCAN récupère des offres depuis des providers configurés, filtre les opportunités pertinentes, les analyse avec **LM Studio** via son API compatible OpenAI, leur attribue un score de pertinence, puis déclenche une alerte pour les meilleures opportunités.

---

## Fonctionnement

```text
Provider -> JobProcessor -> OpenAIClient (LM Studio) -> ScoringService -> DB -> Notification
```

1. **Provider** : récupère les offres depuis une ou plusieurs sources
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
* LM Studio (analyse IA locale)
* Telegram Bot API (notifications)
* Docker (optionnel)

---

## Prérequis

* PHP 8.3+
* Composer
* Symfony CLI
* SQLite
* Docker (optionnel)
* **LM Studio** installé localement

---

## Installation

```bash
git clone <repo>
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

Configurer `.env.local` :

```dotenv
OPENAI_API_BASE=http://localhost:1234/v1
OPENAI_API_KEY=lmstudio
OPENAI_MODEL=local-model

TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...

JOB_FEED_URL_1=
JOB_FEED_URL_2=
JOB_FEED_URL_3=
```

### Explication des variables IA

* `OPENAI_API_BASE` : URL du serveur local LM Studio
* `OPENAI_API_KEY` : valeur arbitraire si l'authentification n'est pas activée dans LM Studio
* `OPENAI_MODEL` : nom exact du modèle servi par LM Studio

---

## LM Studio

JOBSCAN utilise **LM Studio** pour l'analyse locale des offres via son API compatible OpenAI.

### Installer LM Studio

Télécharge et installe LM Studio depuis le site officiel, ou via le paquet `.deb` si tu es sur Linux.

Exemple sur Ubuntu/Debian :

```bash
sudo apt install ./LM-Studio-0.4.12-1-x64.deb
```

---

### Lancer LM Studio

Tu peux lancer l'application avec :

```bash
lm-studio
```

---

### Démarrer le serveur API local

Une fois LM Studio installé, tu peux démarrer le serveur local avec :

```bash
lms server start
```

Le serveur API écoute généralement sur :

```text
http://localhost:1234
```

---

### Vérifier que le serveur fonctionne

```bash
curl http://localhost:1234/v1/models
```

Si tout est OK, tu dois recevoir un JSON contenant les modèles disponibles.

---

### Récupérer le nom exact du modèle

Le champ `id` retourné par :

```bash
curl http://localhost:1234/v1/models
```

doit être utilisé dans :

```dotenv
OPENAI_MODEL=...
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
# ou
tail -f var/alerts.log
```

### Avec Docker

```bash
make up
make run-pipeline
make alerts
```

> Si tu exécutes Symfony dans Docker mais LM Studio sur la machine hôte, adapte `OPENAI_API_BASE` et la configuration Docker en conséquence.

---

## Automatisation (Cron)

JOBSCAN peut tourner de manière entièrement autonome via un cron local. Une fois configuré, il surveille en continu les nouvelles opportunités, les analyse et déclenche des notifications sans aucune intervention manuelle.

### Configurer le cron

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

> **Note** : adapter le chemin du projet, s'assurer que PHP est dans le `PATH` (`which php`), et que LM Studio est lancé pour que l'analyse IA fonctionne.

---

## Analyse IA

L'analyse est effectuée par `OpenAIClient`, qui pointe vers **LM Studio**.

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
| Mention senior          |    +10 |
| Mention mission         |    +10 |
| Urgent / ASAP           |    +15 |
| Stage                   |    -50 |
| Alternance              |    -50 |
| Junior                  |    -20 |

Une notification est déclenchée à partir de **70/100**.

---

## Notifications Telegram

Les opportunités les mieux scorées peuvent être envoyées sur Telegram.

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

SQLite, fichier par environnement :

* dev : `var/data_dev.db`
* prod : `var/data_prod.db`

Exemple d'inspection :

```bash
sqlite3 var/data_dev.db "SELECT id, title, score, source FROM job ORDER BY score DESC;"
```

---

## État actuel du projet

Actuellement :

* **LM Studio** est utilisé pour l'analyse IA
* **Perplexity** peut rester présent dans le codebase mais n'est pas utilisé dans le pipeline principal
* la qualité des résultats dépend des providers activés
* le pipeline fonctionne même sans IA grâce au fallback heuristique

---

## Objectif

JOBSCAN n'est pas un job board.

C'est un filtre intelligent qui transforme un flux d'offres brutes en opportunités réellement exploitables.