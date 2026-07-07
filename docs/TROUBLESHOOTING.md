# Troubleshooting

Problèmes connus et solutions pour la stack Docker (Traefik + nginx + php-fpm) de JOBSCAN.

---

## `Unable to write in the "cache" directory (/app/var/cache/dev)`

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

## `Conflict. The container name "/traefik" is already in use`

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

## Conflit de port `443` / `8080` avec un autre projet Docker

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

## nginx redémarre en boucle : `host not found in upstream "app"`

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

## Le certificat mkcert ne correspond pas à `dynamic.yml`

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
