# Écran React et accès

L'interface de JOBSCAN est un écran React (`frontend/`) qui parle à Symfony **uniquement** par l'API `/api/v1` ([API.md](API.md)). Il contient des données personnelles (contacts de recruteurs, messages envoyés, notes) : il est **fermé par défaut**. Tant qu'aucun mot de passe ni secret de signature n'est configuré, personne ne peut se connecter.

> **Changement de comportement** : l'ancienne interface Twig (`/job`, `/applications`, `/login` servis par Symfony) a été **supprimée**. Ces adresses n'existent plus côté Symfony ; l'écran React sert `/jobs`, `/applications`, `/analytics` et `/login` (routes du navigateur).

## Activer l'accès

```bash
php bin/console app:ui:hash-password        # saisie masquée, 12 caractères minimum, confirmation
openssl rand -hex 32                        # secret de signature des jetons (32 caractères minimum)
```

Ajoutez à `app/.env.local` (ignoré par Git), le hash **entre apostrophes** (il contient des `$`) :

```dotenv
JOBSCAN_UI_USER=admin
JOBSCAN_UI_PASSWORD_HASH='$2y$...'
JOBSCAN_API_JWT_SECRET=<la valeur de openssl rand -hex 32>
```

Seul le **hash** est stocké, jamais le mot de passe. Il n'y a qu'un compte : outil personnel, pas service multi-utilisateurs. Sans `JOBSCAN_API_JWT_SECRET`, la connexion répond `503 auth_not_configured` (fermé, jamais ouvert). L'écran React affiche alors « Authentification non configurée » avec les réglages à vérifier (de même sans mot de passe configuré). Appliquez aussi les migrations (`make migrate`) : la table `refresh_token` en fait partie.

## Ce qui est protégé

| Protection | Détail |
|---|---|
| Authentification | Jeton d'accès JWT (15 min, algorithme imposé, en mémoire dans le navigateur, jamais dans le stockage local) et cookie de rafraîchissement `HttpOnly`, `SameSite=Strict`, limité à `/api/v1/auth`, `Secure` en HTTPS, à usage unique (rotation ; la réutilisation d'un ancien cookie révoque la famille). Toute route de l'API est protégée sans rien faire, sauf la connexion, le rafraîchissement, la déconnexion, la racine et les sondes. |
| Force brute | 5 tentatives par 15 minutes par identifiant et adresse, 20 par adresse ; au-delà, même le bon mot de passe est refusé (`429`). Message identique pour un identifiant inconnu et un mauvais mot de passe. |
| CSRF | Sans objet : l'API n'utilise aucun cookie pour authentifier une requête (le jeton voyage dans `Authorization`), et le cookie de rafraîchissement n'est envoyé qu'à `/api/v1/auth` par le même site. |
| En-têtes de l'écran | CSP stricte posée par nginx (`default-src 'none'`, scripts et styles limités à `'self'`, polices Google, `frame-ancestors 'none'`), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`. Source unique : `frontend/security-headers.conf`. |
| En-têtes de l'API | Chaque réponse porte `default-src 'none'`, `frame-ancestors 'none'`, `nosniff`, `no-referrer`, `Cache-Control: no-store`. |
| Contenu | React échappe tout. Une URL d'annonce n'est cliquable que si elle est en `http(s)` (l'API la filtre) et s'ouvre avec `noopener noreferrer`. |
| Saisies | Statuts, canaux, CV et dates sont validés par les mêmes règles que les commandes (transitions interdites, dates dans le futur, CV non déclaré). Une saisie refusée ne change rien (`422` avec le détail par champ). |

## Fonctions

- **Offres** (`/jobs`) : filtres et tri dans l'adresse, *Suivre* (brouillon : rien n'est envoyé), *Sauvegarder*, *Ignorer*, *Archiver*, *Retirer la décision*.
- **Candidatures** (`/applications`, `/applications/:id`) : onglets par statut, relances dues, changement de statut (transitions permises, date et note), informations, historique.
- **Analytics** (`/analytics`) : parcours, répartitions, rendement des sources ; « x/y » sous 5 candidatures.

Détail des écrans et des routes : [API.md](API.md).

## Exposition

- **Pile Docker de développement** : `make up`, puis **`https://jobscan.local:8443`** (alias `https://app.jobscan.local:8443` ; jamais `http://` : le port 8443 est celui de Traefik en HTTPS). nginx est la porte d'entrée : `/api/` et `/health` vont à Symfony, tout le reste au serveur Vite (rechargement à chaud). `docker-compose.yml` fixe `TRUSTED_PROXIES=REMOTE_ADDR` : php-fpm ne reçoit que les requêtes de nginx, dont il relaie `X-Forwarded-*` de Traefik ; sans cela, le cookie de rafraîchissement ne serait pas marqué `Secure`.
- **Production** : `make prod-up` (voir [DEPLOY.md](DEPLOY.md)). L'image nginx contient l'écran React construit ; une seule origine, sur `127.0.0.1:8088`, derrière votre proxy HTTPS.
- **Derrière Traefik ou un autre proxy HTTPS** : définissez `TRUSTED_PROXIES` avec l'**adresse ou le réseau exact de votre proxy** (ex. `172.18.0.0/16`) pour que HTTPS soit détecté ; n'y mettez que vos propres proxys. **Évitez `private_ranges`** (CVE-2026-121619 : il fait confiance à des clients présentant certaines adresses IPv6 de transition, jusqu'à Symfony 8.1.11).
- N'exposez pas l'application sur Internet sans HTTPS : le mot de passe ne protège pas un échange en clair.

## Développer l'écran

Voir [`frontend/README.md`](../frontend/README.md) : `make front-check` (types, lint, format, build), `make front-e2e` (Playwright, API simulée), `make front-bash`.

## Limites

- Un seul compte, pas de rôles, pas d'authentification à deux facteurs.
- La limitation des tentatives est stockée dans le cache de l'application : vider le cache la réinitialise.
- Pas de journal d'audit des actions.
- Deux rafraîchissements simultanés avec le même cookie révoquent la session (l'écran les sérialise avec les Web Locks dans un même navigateur).
- Les polices viennent de Google Fonts : la CSP les autorise explicitement.
