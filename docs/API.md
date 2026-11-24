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
| `GET /health/ready` | Sonde de **disponibilité** : la base répond (`200`), sinon `503`. Publique, sans enveloppe, un booléen par dépendance, jamais le détail d'une panne. |

Le code vit sous `src/Api/` : `Shared/` (enveloppe, contrôleur de base, erreurs), `Probe/` (sondes, montées hors préfixe), puis un dossier par domaine (`Info/`, et plus tard `Identity/`, `Jobs/`, `Applications/`, `Analytics/`). `config/routes.yaml` monte `src/Api/` sous `/api/v1`, les sondes sans préfixe et `src/Controller/` (interface Twig) tel quel.

## Sécurité

Seuls `/health`, `/health/ready` et `/api/v1/` sont publics (`access_control`) ; ils ne révèlent rien de l'installation. **Toute autre route de l'API est protégée** par la règle générale `^/ → ROLE_USER` jusqu'à l'arrivée de l'authentification de l'API (lot API 1 : JWT d'accès + cookie de rafraîchissement, pare-feu `^/api` sans état).

## Plan du chantier

- [x] **API 0** — enveloppe, erreurs JSON, sondes, racine de l'API
- [ ] **API 1** — authentification : login, refresh rotatif, logout, me ; pare-feu `^/api` sans état ; table `refresh_token`
- [ ] **API 2** — lecture : offres (filtres, pagination), candidatures, analytics
- [ ] **API 3** — écriture : suivre, décider, statut, détails
- [ ] **Front 0** — squelette `frontend/` (Vite, React 19, TypeScript strict, ESLint, Prettier, Playwright), design-system, routeur, client HTTP, authentification, Docker et Traefik
- [ ] **Front 1 à 3** — Offres, Candidatures, Analytics
- [ ] **Final** — retrait de l'interface Twig, documentation

Choix retenus : `/api/v1` sur **la même origine** (Traefik route le chemin vers Symfony et le reste vers le front : ni CORS ni nouveau certificat), JWT via `firebase/php-jwt`, i18n `i18next` en français seul au départ, interface Twig conservée jusqu'au dernier lot.
