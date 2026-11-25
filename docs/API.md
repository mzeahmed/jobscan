# API Jobscan (`/api/v1`)

Chantier : un **front React** (dossier `frontend/`) qui consomme Symfony comme API, sur le modèle de l'API et du front Yoostart v4. L'interface Twig actuelle reste en service pendant toute la migration.

## Contrat de réponse

Toute réponse JSON de `/api/v1/*` est dans la même enveloppe :

```json
{"code": "success", "success": true, "data": {"message": "OK", "…": "…"}}
{"code": "not_found", "success": false, "data": {"message": "Ressource introuvable."}}
```

- `code` : `success`, ou un code d'erreur stable (`not_found`, `unauthorized`, `forbidden`, `unprocessable`, `too_many_requests`…) sur lequel le front peut s'appuyer ;
- `data.message` : texte français destiné à l'utilisateur, toujours présent ; les erreurs peuvent ajouter des champs (ex. `fields`) ;
- `204` : aucune enveloppe, corps vide.

`App\Api\Shared\ApiResponse` fabrique ces réponses et `AbstractApiController` les expose aux contrôleurs (`success()`, `error()`, `noContent()`). Un contrôleur retourne ses erreurs prévues ; les autres sont gérées par `ApiExceptionSubscriber`.

### Erreurs sur `/api/*`

`ApiExceptionSubscriber` met **toute** erreur d'une route `/api/…` dans l'enveloppe, jamais en page HTML ni en redirection :

| Cas | Réponse |
|---|---|
| Route inconnue | `404` `not_found` |
| Méthode non prévue | `405` `method_not_allowed`, en-tête `Allow` |
| Trop de requêtes | `429` `too_many_requests`, en-tête `Retry-After` |
| Refus d'accès, sans utilisateur authentifié | `401` `unauthorized` (pas de redirection vers `/login`) |
| Refus d'accès, utilisateur authentifié | `403` `forbidden` |
| Toute autre exception | `500` `internal_error`, message générique |

- **Le texte d'une exception n'est jamais renvoyé** (il peut contenir une requête SQL, un chemin, un secret) : il est journalisé (`api_exception`). En mode debug seulement, la classe et le message sont ajoutés sous `data.debug` pour une erreur serveur.
- Seuls les en-têtes `Allow` et `Retry-After` d'une exception HTTP sont relayés.
- Les routes hors `/api` ne sont pas touchées : l'interface web garde ses redirections et ses pages d'erreur.

## Routes

| Route | Rôle |
|---|---|
| `GET /api/v1/` | Identité et version du service. Publique, dans l'enveloppe. |
| `GET /health` | Sonde de **vivacité** : `{"status":"ok","service":"jobscan"}`. Publique, **sans enveloppe**, hors préfixe (lue par Docker ou un répartiteur de charge). |
| `POST /api/v1/auth/login` | `{username, password}` (JSON exigé) → session : `{user:{id,username,roles}, access_token, token_type:"Bearer", expires_in}` + cookie de rafraîchissement. `401 invalid_credentials`, `422`, `429` (+ `Retry-After`), `503 auth_not_configured`. Publique. |
| `POST /api/v1/auth/refresh` | Sans corps ; échange le cookie contre une nouvelle session et un **nouveau cookie** (rotation). `401` (même réponse si cookie absent, inconnu, expiré, révoqué ou déjà utilisé). Publique. |
| `POST /api/v1/auth/logout` | Révoque la famille du cookie et l'efface ; `204`, idempotent. Publique. |
| `GET /api/v1/auth/me` | L'utilisateur du jeton d'accès. Protégée. |
| `GET /health/ready` | Sonde de **disponibilité** : la base répond (`200`), sinon `503`. Publique, sans enveloppe, un booléen par dépendance, jamais le détail d'une panne. |

Le code vit sous `src/Api/` : `Shared/` (enveloppe, contrôleur de base, erreurs), `Probe/` (sondes, montées hors préfixe), puis un dossier par domaine (`Info/`, et plus tard `Identity/`, `Jobs/`, `Applications/`, `Analytics/`). `config/routes.yaml` monte `src/Api/` sous `/api/v1`, les sondes sans préfixe et `src/Controller/` (interface Twig) tel quel.

## Sécurité

Seuls `/health`, `/health/ready`, `/api/v1/` et `/api/v1/auth/{login,refresh,logout}` sont publics (`access_control`) ; **toute autre route de l'API exige un jeton d'accès** (règle générale `^/ → ROLE_USER`).

- **Pare-feu `api`** (`^/api`) : sans état, sans session ni cookie PHP ; `JwtAuthenticator` lit `Authorization: Bearer`. Sans jeton : `401` JSON (jamais de redirection vers `/login`). Le pare-feu `main` (interface Twig, session) est indépendant : un jeton d'API n'ouvre pas l'interface web et la session web n'ouvre pas l'API.
- **Jeton d'accès** : JWT HS256, 15 minutes, porté **en mémoire** par le front. L'algorithme est imposé à la vérification (pas d'`alg=none`), l'émetteur vérifié ; il ne contient que l'identifiant et les rôles.
- **Jeton de rafraîchissement** : secret aléatoire de 256 bits, 30 jours, **à usage unique** (rotation à chaque rafraîchissement), posé en cookie `jobscan_refresh` : `HttpOnly`, `SameSite=Strict`, `Secure` dès que la requête est en HTTPS, chemin `/api/v1/auth` (il ne voyage pas avec chaque appel). Seule son **empreinte SHA-256** est stockée (table `refresh_token`). Présenter un jeton déjà utilisé révoque toute sa famille (vol probable) ; l'expiration purge les anciens jetons à la connexion.
- **Connexion** : limitation du débit (5 échecs / 15 min par adresse + identifiant, 20 par adresse), message et temps de calcul identiques pour « identifiant inconnu » et « mauvais mot de passe », champs bornés à 256 caractères, `Content-Type: application/json` exigé.
- **Désactivée plutôt qu'ouverte** : sans `JOBSCAN_API_JWT_SECRET` (32 caractères minimum) ou sans compte configuré (`JOBSCAN_UI_PASSWORD_HASH`), `login` et `refresh` répondent `503`.
- **Compte** : le même compte unique que l'interface web (`JOBSCAN_UI_USER` / `JOBSCAN_UI_PASSWORD_HASH`, voir `docs/UI.md`).

### Mise en service

Dans `app/.env.local` : `JOBSCAN_API_JWT_SECRET=$(openssl rand -hex 32)` (valeur propre à chaque installation, jamais dans le dépôt), puis `make migrate` (table `refresh_token`). Changer le secret déconnecte tout le monde (les jetons d'accès émis deviennent invalides ; les cookies restent valides jusqu'à leur expiration).

Limite connue : deux rafraîchissements simultanés avec le même cookie (deux onglets non coordonnés) font échouer le second et révoquent la session ; le front les sérialise avec un verrou Web Locks.

## Plan du chantier

- [x] **API 0** — enveloppe, erreurs JSON, sondes, racine de l'API
- [x] **API 1** — authentification : login, refresh rotatif, logout, me ; pare-feu `^/api` sans état ; table `refresh_token`
- [ ] **API 2** — lecture : offres (filtres, pagination), candidatures, analytics
- [ ] **API 3** — écriture : suivre, décider, statut, détails
- [x] **Front 0** — squelette `frontend/` (Vite, React 19, TypeScript strict, ESLint, Prettier, Playwright), design-system, routeur, client HTTP, authentification, Docker et Traefik
- [ ] **Front 1 à 3** — Offres, Candidatures, Analytics
- [ ] **Final** — retrait de l'interface Twig, documentation

Choix retenus : `/api/v1` sur **la même origine** (Traefik route le chemin vers Symfony et le reste vers le front : ni CORS ni nouveau certificat), JWT via `firebase/php-jwt`, i18n `i18next` en français seul au départ, interface Twig conservée jusqu'au dernier lot.

## Front (`frontend/`)

Front 0 livré : React 19, TypeScript strict, Vite, TanStack Query, react-router, i18next (français), design-system `js-*` (variables reprises de l'interface Twig), client HTTP `symfonyClient` (rafraîchissement du jeton en vol unique, verrou Web Locks entre onglets).
Commandes : `make front-check` (types, lint, format, build) et `make front-e2e` (Playwright, build de production avec CSP stricte, API simulée).

- **Développement** : `make up` lance le conteneur `frontend` (Vite). Accès `https://app.jobscan.local:8443` (nginx route `/api` et `/health` vers Symfony, le reste vers Vite — une seule origine, donc ni CORS ni cookie inter-site) ou directement `http://localhost:5173`. `make hosts` ajoute `app.jobscan.local`.
- **Production** : pas d'image dédiée pour l'instant ; le build statique sera servi par nginx au lot « Final ».
- **Contrat d'authentification** : `POST /auth/login {username,password}` et `POST /auth/refresh` (cookie HttpOnly) renvoient `{user:{id,username,roles}, access_token, token_type, expires_in}` ; `POST /auth/logout` répond 204.
- **Vérifié** contre l'API réelle (API 1) : connexion, `me`, rotation, déconnexion, `/job` toujours protégé.
