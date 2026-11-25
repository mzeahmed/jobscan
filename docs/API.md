# API Jobscan (`/api/v1`)

Chantier : un **front React** (dossier `frontend/`) qui consomme Symfony comme API, sur le modèle de l'API et du front Yoostart v4. La migration est terminée : l'ancienne interface Twig a été supprimée (lot Final).

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
- Hors `/api` et sondes, Symfony n'expose plus rien (`404`) : les écrans sont ceux du front React.

## Routes

| Route | Rôle |
|---|---|
| `GET /api/v1/` | Identité et version du service. Publique, dans l'enveloppe. |
| `GET /health` | Sonde de **vivacité** : `{"status":"ok","service":"jobscan"}`. Publique, **sans enveloppe**, hors préfixe (lue par Docker ou un répartiteur de charge). |
| `POST /api/v1/auth/login` | `{username, password}` (JSON exigé) → session : `{user:{id,username,roles}, access_token, token_type:"Bearer", expires_in}` + cookie de rafraîchissement. `401 invalid_credentials`, `422`, `429` (+ `Retry-After`), `503 auth_not_configured`. Publique. |
| `POST /api/v1/auth/refresh` | Sans corps ; échange le cookie contre une nouvelle session et un **nouveau cookie** (rotation). `401` (même réponse si cookie absent, inconnu, expiré, révoqué ou déjà utilisé). Publique. |
| `POST /api/v1/auth/logout` | Révoque la famille du cookie et l'efface ; `204`, idempotent. Publique. |
| `GET /api/v1/auth/me` | L'utilisateur du jeton d'accès. Protégée. |
| `GET /api/v1/jobs` | Offres filtrées, triées, paginées : `{items, pagination:{page,per_page,total,total_pages}, sources}`. Paramètres : `location`, `remote` (`remote`\|`onsite`), `contract`, `seniority`, `freelance` et `notified` (`yes`\|`no`), `min_score` (0-100), `source`, `age` (`1`\|`7`\|`30` jours), `sort` (`date`\|`score`), `state` (`saved`\|`ignored`\|`archived`\|`all`, défaut : offres sans décision), `page`, `per_page` (défaut 20, max 100). Valeur inconnue : `422` avec `data.errors` par paramètre (jamais ignorée en silence). Page au-delà de la dernière : ramenée à la dernière. |
| `GET /api/v1/jobs/{id}` | Détail : résumé + `description` (texte brut), `score_breakdown`, `requirements`, `budget`, `publication`, `analysis`, doublon probable. `404` si inconnue. |
| `GET /api/v1/applications` | Candidatures (200 au plus, récentes d'abord), filtre `?status=` ; `counts` (un compteur par statut, indépendant du filtre), `total`, `due_follow_ups`. |
| `GET /api/v1/applications/{id}` | `{application, options}` : candidature, `history` (étapes), `allowed_transitions`, notes et message envoyé ; `options` : `channels` et `cv_versions` (versions de CV du profil candidat, liste vide sans profil) pour les formulaires. |
| `GET /api/v1/analytics` | `?since=30d\|90d\|365d\|all` (défaut `90d`) : `funnel`, `breakdowns` (source, cv, profile, channel), `sourcing`. Les taux valent `null` sous `min_sample` (5) : le front affiche « x/y », jamais un pourcentage trompeur. |
| `POST /api/v1/jobs/{id}/application` | Ouvre le suivi d'une offre : une candidature **brouillon** (rien n'est envoyé). `201` + `created: true` ; si l'offre est déjà suivie : `200` + `created: false` et la candidature existante (idempotent). |
| `PUT /api/v1/jobs/{id}/decision` | `{"state": "saved"\|"ignored"\|"archived"\|null}` : décision de l'utilisateur (`null` la retire ; clé absente : `422`). Idempotent. Renvoie le résumé de l'offre. |
| `POST /api/v1/applications/{id}/status` | `{"status", "at"?, "note"?}` : consigne un changement de statut **déclaré** (`at` : date passée, `note` : 500 caractères max). Transition interdite : `422 invalid_transition` avec les statuts possibles. Renvoie la candidature et son historique. |
| `PATCH /api/v1/applications/{id}` | Mise à jour **partielle** : `recruiter_name`, `recruiter_contact`, `channel`, `cv`, `message_sent`, `notes`, `follow_up_at`. Champ absent : inchangé ; `null` ou vide : effacé. Champ inconnu (dont `status`) ou texte trop long : `422`, et **rien** n'est enregistré. |
| `GET /health/ready` | Sonde de **disponibilité** : la base répond (`200`), sinon `503`. Publique, sans enveloppe, un booléen par dépendance, jamais le détail d'une panne. |

Le code vit sous `src/Api/` : `Shared/` (enveloppe, contrôleur de base, erreurs), `Probe/` (sondes, montées hors préfixe), puis un dossier par domaine (`Info/`, et plus tard `Identity/`, `Jobs/`, `Applications/`, `Analytics/`). `config/routes.yaml` monte `src/Api/` sous `/api/v1`, et les sondes sans préfixe.

## Sécurité

Seuls `/health`, `/health/ready`, `/api/v1/` et `/api/v1/auth/{login,refresh,logout}` sont publics (`access_control`) ; **toute autre route de l'API exige un jeton d'accès** (règle générale `^/ → ROLE_USER`).

- **Pare-feu `api`** (`^/api`) : sans état, sans session ni cookie PHP ; `JwtAuthenticator` lit `Authorization: Bearer`. Sans jeton : `401` JSON (jamais de redirection vers `/login`). Le pare-feu `main` (tout le reste) refuse l'accès par défaut.
- **Jeton d'accès** : JWT HS256, 15 minutes, porté **en mémoire** par le front. L'algorithme est imposé à la vérification (pas d'`alg=none`), l'émetteur vérifié ; il ne contient que l'identifiant et les rôles.
- **Jeton de rafraîchissement** : secret aléatoire de 256 bits, 30 jours, **à usage unique** (rotation à chaque rafraîchissement), posé en cookie `jobscan_refresh` : `HttpOnly`, `SameSite=Strict`, `Secure` dès que la requête est en HTTPS, chemin `/api/v1/auth` (il ne voyage pas avec chaque appel). Seule son **empreinte SHA-256** est stockée (table `refresh_token`). Présenter un jeton déjà utilisé révoque toute sa famille (vol probable) ; l'expiration purge les anciens jetons à la connexion.
- **Connexion** : limitation du débit (5 échecs / 15 min par adresse + identifiant, 20 par adresse), message et temps de calcul identiques pour « identifiant inconnu » et « mauvais mot de passe », champs bornés à 256 caractères, `Content-Type: application/json` exigé.
- **Désactivée plutôt qu'ouverte** : sans `JOBSCAN_API_JWT_SECRET` (32 caractères minimum) ou sans compte configuré (`JOBSCAN_UI_PASSWORD_HASH`), `login` et `refresh` répondent `503`.
- **Compte** : le compte unique historique de l'interface (`JOBSCAN_UI_USER` / `JOBSCAN_UI_PASSWORD_HASH`, voir `docs/UI.md`).

### Mise en service

Dans `app/.env.local` : `JOBSCAN_API_JWT_SECRET=$(openssl rand -hex 32)` (valeur propre à chaque installation, jamais dans le dépôt), puis `make migrate` (table `refresh_token`). Changer le secret déconnecte tout le monde (les jetons d'accès émis deviennent invalides ; les cookies restent valides jusqu'à leur expiration).

Limite connue : deux rafraîchissements simultanés avec le même cookie (deux onglets non coordonnés) font échouer le second et révoquent la session ; le front les sérialise avec un verrou Web Locks.

### Écritures

- Jeton d'accès (`Authorization: Bearer`) exigé, corps `application/json` (`415` sinon) et objet JSON (`400` sinon). Un jeton porté en en-tête n'est jamais envoyé automatiquement par le navigateur : pas de jeton CSRF à gérer.
- Les saisies sont **validées, jamais corrigées en silence** : un texte trop long, une date invalide ou future, un canal ou un CV inconnu donnent `422` avec `data.errors` par champ ; les notes de l'utilisateur ne sont jamais tronquées sans le dire.
- Une erreur n'enregistre rien (validation avant toute modification, un seul `flush`).
- **Aucun envoi** : ces routes ne font que consigner ce que l'utilisateur déclare. Rien n'est envoyé à un recruteur ni à un tiers.
- Le texte saisi (notes, message envoyé) est stocké et renvoyé tel quel, sans interprétation ; le front l'affiche comme du texte.

### Contenus non fiables (offres)

Une annonce est écrite par un tiers : l'API n'en publie que du **texte brut** (balises retirées, entités décodées, longueur bornée : extrait de 240 caractères en liste, 20 000 en détail) et une **URL `http(s)`** (`url` vaut `null` sinon, jamais `javascript:`). Le front affiche ces champs comme du texte, jamais comme du HTML. Les dates sont en ISO 8601 ; un champ inconnu vaut `null`, jamais une valeur inventée.

## Plan du chantier

- [x] **API 0** — enveloppe, erreurs JSON, sondes, racine de l'API
- [x] **API 1** — authentification : login, refresh rotatif, logout, me ; pare-feu `^/api` sans état ; table `refresh_token`
- [x] **API 2** — lecture : offres (filtres, pagination), candidatures, analytics
- [x] **API 3** — écriture : suivre, décider, statut, détails
- [x] **Front 0** — squelette `frontend/` (Vite, React 19, TypeScript strict, ESLint, Prettier, Playwright), design-system, routeur, client HTTP, authentification, Docker et Traefik
- [x] **Front 1** — Offres : liste, filtres dans l'adresse, pagination, suivre, décider
- [x] **Front 2** — Candidatures : liste par statut, détail, historique, changement de statut, édition
- [x] **Front 3** — Analytics : parcours, répartitions, rendement des sources
- [x] **Final** — retrait de l'interface Twig (contrôleurs, gabarits, assets, TwigBundle, AssetMapper, TypeScriptBundle), image nginx de production avec l'écran construit, CI du front, documentation

Choix retenus : `/api/v1` sur **la même origine** (Traefik route le chemin vers Symfony et le reste vers le front : ni CORS ni nouveau certificat), JWT via `firebase/php-jwt`, i18n `i18next` en français seul au départ, interface Twig supprimée au dernier lot.

## Front (`frontend/`)

Front 0 livré : React 19, TypeScript strict, Vite, TanStack Query, react-router, i18next (français), design-system `js-*` (variables reprises de l'interface Twig), client HTTP `symfonyClient` (rafraîchissement du jeton en vol unique, verrou Web Locks entre onglets).
Commandes : `make front-check` (types, lint, format, build) et `make front-e2e` (Playwright, build de production avec CSP stricte, API simulée).

- **Développement** : `make up` lance le conteneur `frontend` (Vite). Accès `https://jobscan.local:8443` (ou son alias `https://app.jobscan.local:8443`) (nginx route `/api` et `/health` vers Symfony, le reste vers Vite — une seule origine, donc ni CORS ni cookie inter-site) ou directement `http://localhost:5173`. `make hosts` ajoute `app.jobscan.local`.
- **Production** : l'image nginx (`.docker/nginx/Dockerfile`) construit l'écran (étape Node, comme Coelbook) et le sert ; `/api/` et `/health` sont relayés à php-fpm (`.docker/nginx/prod.conf`). Une seule origine, sur `127.0.0.1:8088`.
- **Contrat d'authentification** : `POST /auth/login {username,password}` et `POST /auth/refresh` (cookie HttpOnly) renvoient `{user:{id,username,roles}, access_token, token_type, expires_in}` ; `POST /auth/logout` répond 204.
- **Vérifié** contre l'API réelle (API 1) : connexion, `me`, rotation, déconnexion.

### Écran Offres (Front 1)

- `/jobs` : liste paginée (`GET /api/v1/jobs`), cartes avec anneau de score, filtres et tri.
- **Filtres dans l'adresse** (`?source=rss&min_score=60&page=2`, mêmes noms que les paramètres de l'API) : la page se partage, le bouton « précédent » fonctionne. L'adresse est relue sans jamais échouer : une valeur absurde retombe sur le défaut. Les champs ne sont envoyés qu'au clic sur « Filtrer » (pas de requête à chaque frappe) ; changer un filtre revient à la page 1.
- **Actions** : « Suivre » (brouillon, rien n'est envoyé), « Sauvegarder / Ignorer / Archiver / Retirer la décision ». Une action en échec est signalée et l'offre reste utilisable.
- **Annonces non fiables** : titre, entreprise et extrait sont rendus comme du texte (jamais comme du HTML) ; le lien vers l'annonce n'existe que si l'API fournit une URL http(s), et s'ouvre avec `noopener noreferrer`. Un score absent s'affiche « — », jamais 0.

### Écrans Candidatures (Front 2)

- `/applications` : onglets par statut avec compteurs (liens `?status=`, l'adresse porte le filtre ; un statut inconnu est ignoré et jamais envoyé), bandeau des relances dues, cartes avec statut, canal, CV, date d'envoi, relance.
- `/applications/:id` : changement de statut (**seules les transitions que l'API renvoie sont proposées**, date et note facultatives ; un statut final n'a plus de formulaire), informations (recruteur, canal, CV, relance, message, notes) et historique du plus récent au plus ancien. Un identifiant invalide n'appelle jamais l'API : même écran qu'une candidature inconnue.
- **Édition** : texte vide = champ effacé (`null`) ; le CV n'est envoyé que si le profil en propose (sinon le CV associé n'est pas touché). Une erreur `422` s'affiche sous le champ concerné et **garde la saisie** ; une panne du serveur est annoncée sans perdre le texte.
- Notes et message sont des textes de l'utilisateur, affichés et renvoyés tels quels, jamais interprétés. Rien n'est envoyé à un recruteur : l'écran le rappelle.

### Écran Analytics (Front 3)

- `/analytics` : `GET /api/v1/analytics?since=`. La période se choisit par des liens (`30d`, `90d` par défaut, `365d`, `all`) ; l'adresse la porte (`?since=30d`, rien pour la valeur par défaut) et une valeur inconnue est ignorée, jamais envoyée.
- **Parcours** : envoyées → réponses → entretiens → propositions (barres `<progress>`, sans style en ligne), puis acceptées, refusées, sans réponse et délai médian (« Inconnu » s'il n'y a aucune réponse).
- **Répartitions** : par source, version du CV, profil de recherche et canal, en tableaux.
- **Jamais de pourcentage trompeur** : le taux vient de l'API ; `null` (moins de `min_sample` = 5 candidatures) donne « x/y », sinon « x/y (NN %) ». Le front ne calcule aucun pourcentage lui-même.
- **Rendement des sources** : offres collectées → compatibles → notifiées → suivies → postulées. Affiché même sans candidature envoyée ; sans candidature, un état vide remplace le parcours.
- Les libellés (source, CV, profil) peuvent venir de contenus externes : rendus comme du texte.
- Tous les écrans de l'ancienne interface sont migrés : l'écran « Bientôt disponible » est supprimé.

### Lot Final

- **Supprimé** : `src/Controller/`, `templates/`, `assets/`, les extensions Twig, le formulaire de connexion et ses tests, `symfony/twig-bundle`, `symfony/asset`, `symfony/asset-mapper`, `sensiolabs/typescript-bundle` (avec eux, 9 avis de sécurité de dépendances disparaissent), les commandes `make w`/`make b` et le hook d'assets.
- **En-têtes de sécurité** : `SecurityHeadersSubscriber` ne pose plus de nonce (plus de page HTML côté Symfony) : chaque réponse de l'API porte `default-src 'none'`, `frame-ancestors 'none'`, `nosniff`, `no-referrer`, `no-store`. La CSP de l'écran React est dans `frontend/security-headers.conf` (source unique, relue par Vite).
- **Image de production** : `docker-compose.prod.yml` ne passe plus `additional_contexts` à nginx (plus de ressources PHP à copier) ; l'image nginx embarque `frontend/dist`.
- **Développement** : `jobscan.local` et `app.jobscan.local` mènent au même écran (Vite derrière nginx) ; `/api` et `/health` vont à Symfony.
- **CI** : un travail `frontend` (types, ESLint, Prettier, build, Playwright) s'ajoute au travail PHP.
- **Vérifié de bout en bout** sur la pile de production construite (projet Docker isolé, identifiants factices) : connexion réelle depuis un navigateur, les trois écrans, rechargement avec session conservée, déconnexion, aucune violation de CSP ; `/api/v1/*` répond avec le jeton ; `/login` côté Symfony n'existe plus.
