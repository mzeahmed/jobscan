# Déploiement Docker autonome

Deux piles Docker, indépendantes :

| | Développement (`docker-compose.yml`) | **Autonome** (`docker-compose.prod.yml`) |
|---|---|---|
| Code | monté depuis `./app` | **figé dans l'image** |
| Utilisateur | le vôtre (`HOST_UID`/`HOST_GID`) | `app`, sans privilège (uid 10001) |
| Données | `./app/var` | volume Docker `var` (base SQLite, cache, journaux) |
| Planification | aucune | **service `cron`** (supercronic), optionnel |
| Boutons Telegram | à lancer à la main | **service `telegram`**, optionnel |
| Migrations | `make migrate` | au démarrage (`JOBSCAN_AUTO_MIGRATE=1`) |

## Pile autonome

```bash
make prod-up              # web seul : http://127.0.0.1:8088/login
make prod-up-workers      # + planification et boutons Telegram
make prod-down            # arrête (le volume `var` est conservé)
make prod-logs
```

(ou directement `docker compose -f docker-compose.prod.yml [--profile workers] up -d --build`.)

Avant le premier démarrage :

1. **Identifiants** dans `app/.env.local` (ignoré par Git) : `APP_SECRET`, `JOBSCAN_UI_PASSWORD_HASH` (voir [UI.md](UI.md)), Telegram, clés LLM. Ce fichier est lu **à l'exécution** (`env_file`) et n'est **jamais copié dans une image** : `.dockerignore` exclut `app/.env`, `app/.env.local` et le profil candidat.
2. **Profil candidat** : `app/config/profile/local/` est monté en **lecture seule** sur `/profile`. Autre dossier : `JOBSCAN_PROFILE_DIR=/chemin make prod-up`.
3. **LLM** : depuis un conteneur, `localhost` n'est pas votre machine. Pointez `OLLAMA_BASE_URL` vers l'hôte (`http://host.docker.internal:11434/v1`, ce qui reste un moteur « local » pour l'assistant) et mettez à jour `extra_hosts` si besoin.

L'image est construite en plusieurs étapes (`dev`, `build`, `prod`) : dépendances sans `--dev`, autoloader optimisé, ressources compilées, cache chauffé, OPcache sans revalidation. Le service `app` démarre php-fpm **après** avoir appliqué les migrations (non destructives) ; `nginx`, `cron` et `telegram` attendent qu'il soit sain.

## Planification (`cron`)

`docker/cron/crontab` (copié dans l'image) :

| Tâche | Fréquence |
|---|---|
| `app:jobs:run` (collecte, analyse, matching, notifications) | toutes les 3 h |
| `app:jobs:analyze` (reprise des analyses en attente ou en échec) | toutes les heures |
| `app:reminders:send` (relances dues) | toutes les heures |
| `app:notifications:digest` (récapitulatif et rattrapage) | tous les jours à 18 h |
| `app:health:check --alert` (alerte Telegram au changement d'état) | toutes les heures |
| `app:report:weekly --telegram` | le dimanche à 18 h 30 |
| `app:jobs:purge` | **désactivée** : à décommenter en connaissance de cause |

Pourquoi supercronic et non Symfony Scheduler : voir [SCHEDULER.md](SCHEDULER.md). Chaque tâche prend un `flock` : une exécution encore en cours n'est jamais doublée. Fuseau : `TZ` (Europe/Paris par défaut). Pour changer les horaires, modifiez le fichier et reconstruisez (`make prod-up-workers`).

## Sécurité

- **Aucun secret dans une image.** (Avant ce lot, le `.dockerignore` n'excluait ni `app/.env` ni `app/.env.local` : le `COPY` du Dockerfile les embarquait.)
- Services applicatifs : racine en **lecture seule**, **aucune capacité Linux**, `no-new-privileges`, `/tmp` en `tmpfs`, utilisateur sans privilège.
- Le web n'écoute que sur **`127.0.0.1`** (`JOBSCAN_HTTP_PORT`, 8088 par défaut) : placez devant lui votre proxy HTTPS. N'exposez pas l'interface en clair sur Internet ; `TRUSTED_PROXIES` vaut `REMOTE_ADDR` par défaut dans cette pile (php-fpm ne reçoit que les requêtes de nginx, publié sur `127.0.0.1`) pour que les redirections et le cookie de session suivent le HTTPS de **votre** proxy ; `JOBSCAN_TRUSTED_PROXIES` permet de le changer (voir [UI.md](UI.md)).
- Le tableau de bord Traefik de la pile de développement (`insecure: true`, sans authentification) n'est plus publié que sur `127.0.0.1:9080` (il l'était sur toutes les interfaces).

## Développement : fin des fichiers appartenant à root

Le conteneur de développement tournait en **root** avec `./app` monté : tout ce que Composer ou Symfony y créait (`vendor/`, `var/`) appartenait à root, d'où le `chmod -R 777 app/var` du `makefile` et des `composer install` impossibles depuis l'hôte. Il tourne maintenant sous **votre** identifiant : le `makefile` exporte `HOST_UID`/`HOST_GID` et `docker-compose.yml` les passe à `user:`.

**À faire une fois** (les fichiers déjà créés par root le restent) :

```bash
sudo chown -R "$USER":"$USER" app/vendor app/var
make build && make up       # recrée le conteneur sous votre identifiant
```

## Vérification

La pile a été construite et exécutée de bout en bout dans un projet Docker isolé (identifiants factices) : migrations au démarrage, `/login` et en-têtes de sécurité, connexion avec CSRF, ressources servies par nginx,
racine en lecture seule, aucune capacité, lecture du crontab par supercronic, arrêt propre du service Telegram (0,15 s), aucun secret ni jeton dans l'image ou les journaux. Les tâches planifiées elles-mêmes n'ont **pas** été
déclenchées (elles appellent des services externes) : lancez-en une à la main (`docker compose -f docker-compose.prod.yml exec cron php bin/console app:jobs:run --dry-run`) avant de compter sur l'horaire.

## Limites

- Avec un jeton Telegram invalide, le service `telegram` journalise un refus toutes les 5 secondes (il ne plante pas).

- **SQLite sur un volume** : un seul hôte, plusieurs processus (web, cron, Telegram) qui écrivent peu ; ce n'est pas une architecture multi-serveurs. Pour de la haute disponibilité, il faudrait PostgreSQL.
- **Sauvegarde** : `docker run --rm -v jobscan-prod_var:/var -v "$PWD":/backup alpine tar czf /backup/jobscan-var.tgz -C /var .` (et sauvegardez `app/config/profile/local/`). Rien n'est automatisé.
- **HTTPS** : non fourni par cette pile (la pile de développement a Traefik avec des certificats locaux).
- **Mises à jour** : `git pull && make prod-up` reconstruit l'image ; le volume `var` et les migrations suivent. Pas de déploiement sans interruption.
- Architectures : `amd64` et `arm64` (supercronic épinglé par empreinte pour chacune).
