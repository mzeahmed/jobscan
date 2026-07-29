# Troubleshooting

Problèmes connus et solutions pour la stack Docker (Traefik + nginx + php-fpm) de
JOBSCAN, ainsi que pour l'exécution des commandes `make` en local hors conteneur.

Les entrées sont classées par date, de la plus récente à la plus ancienne : les
nouvelles s'ajoutent **en haut** du fichier, avec la date du jour au format
`JJ/MM/AAAA`.

---

## **29/07/2026** — `Your Composer dependencies require a PHP version ">= 8.4.1". You are running 8.3.x`

**Symptôme** : n'importe quelle cible `make` exécutée en local (hors Docker) échoue
avec une `RuntimeException` levée depuis `app/vendor/composer/platform_check.php` —
`make run-pipeline`, `make cs`, `make stan`, `make test`… Exemple :

```
cd app && php bin/console app:jobs:run
Composer detected issues in your platform:
Your Composer dependencies require a PHP version ">= 8.4.1". You are running 8.3.32.
```

**Cause** : `app/composer.json` requiert `php: >=8.4` (Symfony 8.1 resserre la
contrainte à 8.4.1 dans le `composer.lock`), mais le `php` par défaut de la machine
est plus ancien. Plusieurs versions peuvent cohabiter (`php8.3`, `php8.4`, `php8.5`)
sans que `php` tout court pointe sur la bonne — c'est le lien
`update-alternatives` qui décide.

Le piège : **trois chemins d'invocation distincts** peuvent chacun retomber sur le
mauvais binaire, donc corriger l'un ne suffit pas.

1. `php bin/console …` — le `php` du `PATH` ;
2. `./vendor/bin/phpstan`, `./vendor/bin/rector` — leur shebang `#!/usr/bin/env php` ;
3. `composer run lint` — Composer lance ses scripts comme binaires externes, donc
   `vendor/bin/php-cs-fixer` repart lui aussi sur son shebang, **même si Composer
   tourne déjà sous la bonne version**.

**Solution** : déjà en place, à trois endroits complémentaires.

* `makefile` — la variable `PHP` résout `php8.4` en priorité et est utilisée par
  toutes les cibles PHP :

  ```makefile
  PHP ?= $(shell command -v php8.4 2>/dev/null || command -v php)
  COMPOSER ?= $(PHP) $(shell command -v composer)
  ```

  Surchargeable au besoin : `make test PHP=php8.5`.

* `app/.php-version` (contenu : `8.4`) — les cibles `console` et `migrate` passent
  par le CLI Symfony, qui ne lit pas les variables du makefile mais respecte ce
  fichier. Vérification : `symfony php -v`.

* `app/composer.json` — les scripts `lint` / `lint:fix` sont préfixés par `@php`,
  qui force Composer à réutiliser son propre `PHP_BINARY` au lieu du shebang :

  ```json
  "lint": "@php vendor/bin/php-cs-fixer fix --dry-run --diff"
  ```

Si l'erreur revient : vérifier d'abord qu'un PHP ≥ 8.4.1 est bien installé
(`ls /usr/bin/php8.*`), puis quelle version est réellement utilisée — `make cs`
affiche une ligne `PHP runtime: …`, et `php8.4 bin/console --version` doit répondre
sans erreur. Toute nouvelle cible du makefile invoquant PHP doit utiliser `$(PHP)`
plutôt que `php` en dur.

**Alternative globale** : basculer le `php` par défaut sur 8.4 avec
`sudo update-alternatives --config php` règle les trois chemins d'un coup, mais
affecte tous les projets de la machine — y compris ceux qui doivent rester sur une
version antérieure. Le paramétrage ci-dessus est préféré parce qu'il rend le dépôt
indépendant de la configuration système.

---

## **07/07/2026** — `Unable to write in the "cache" directory (/app/var/cache/dev)`

**Symptôme** : erreur affichée dans le navigateur en accédant à `https://jobscan.local:8443`.

**Cause** : le conteneur `app` exécute php-fpm sous l'utilisateur `www-data` (uid 33).
`app/var/` est monté depuis l'hôte (`./app:/app`) et appartient à votre utilisateur
local — `www-data` n'a donc pas les droits d'écriture dessus.

**Solution** :

```bash
make fix-perms
```

Équivaut à `sudo chmod -R 777 app/var` (le `sudo` est nécessaire si certains fichiers de
cache ont déjà été créés par un processus root). Suffisant en local ; à ne jamais
faire en production.

---

## **07/07/2026** — `Conflict. The container name "/traefik" is already in use`

**Symptôme** : `make up` échoue avec un conflit de nom de conteneur au démarrage de
Traefik.

**Cause** : un autre projet Docker sur la machine utilise déjà un conteneur nommé
`traefik` (chaque projet local fait tourner son propre Traefik). Les noms de
conteneurs sont uniques à l'échelle de tout Docker, pas juste du projet.

**Solution** : dans JOBSCAN, le service s'appelle `jobscan_traefik`
(`container_name` dans `docker-compose.yml`), comme les autres services
(`jobscan_app`, `jobscan_nginx`, `jobscan_searxng`). Si l'erreur persiste avec un nom
déjà préfixé, un conteneur fantôme traîne probablement :

```bash
docker rm -f jobscan_traefik
```

---

## **07/07/2026** — Conflit de port `443` / `8080` avec un autre projet Docker

**Symptôme** : `make up` échoue avec `port is already allocated`, ou bien un autre
projet Traefik répond à la place de JOBSCAN sur `jobscan.local`.

**Cause** : plusieurs projets locaux font chacun tourner leur propre Traefik sur les
ports standards `443`/`8080`. Un seul projet peut les monopoliser à la fois.

**Solution actuelle** : JOBSCAN écoute sur des ports hôte décalés (`8443` pour
HTTPS, `9080` pour le dashboard) plutôt que `443`/`8080` — voir `docker-compose.yml`
(service `traefik`, bloc `ports`). D'où le suffixe dans les URLs :

* `https://jobscan.local:8443`
* `https://searxng.local:8443`
* Dashboard : `http://localhost:9080`

**Pour retirer le suffixe** (si `443`/`8080` sont libres sur la machine, ou si un
seul projet Docker tourne à la fois) : remplacer `"8443:443"` par `"443:443"` (et
`"9080:8080"` par `"8080:8080"`) dans `docker-compose.yml`. Rien à changer côté
`traefik.yml`/`dynamic.yml` — ce n'est qu'un mapping de port Docker.

---

## **07/07/2026** — nginx redémarre en boucle : `host not found in upstream "app"`

**Symptôme** : le conteneur `nginx` crash au démarrage avec cette erreur dans les
logs (`make logs`).

**Cause** : nginx résout le nom `app` au chargement de sa config. Si le conteneur
`app` n'est pas encore inscrit dans le DNS interne de Docker à ce moment-là (ordre
de démarrage), la résolution échoue et nginx refuse de démarrer.

**Solution** : déjà en place dans `docker/nginx/default.conf` — le nom `app` est
résolu via une variable (`set $upstream_app app:9000;` + `resolver 127.0.0.11
valid=10s;`) plutôt qu'en dur dans `fastcgi_pass`, ce qui reporte la résolution DNS
à la requête plutôt qu'au démarrage. Si l'erreur revient après une modification de
ce fichier, vérifier que ce pattern n'a pas été perdu.

---

## **07/07/2026** — Le certificat mkcert ne correspond pas à `dynamic.yml`

**Symptôme** : Traefik ne trouve pas de certificat, ou le navigateur affiche une
alerte de sécurité malgré `mkcert -install`.

**Cause** : `traefik/dynamic.yml` référence des fichiers nommés précisément
`jobscan.local+1.pem` / `jobscan.local+1-key.pem` — c'est la convention de nommage
de mkcert quand on lui donne plusieurs domaines (`<premier domaine>+<n domaines
supplémentaires>`).

**Solution** : générer le certificat avec exactement les deux domaines, dans cet
ordre, depuis le dossier `certs/` :

```bash
mkdir -p certs && cd certs && mkcert jobscan.local searxng.local && cd ..
```

Si les fichiers générés portent un autre nom, mettre à jour les chemins dans
`traefik/dynamic.yml` (bloc `tls.certificates`) en conséquence.
