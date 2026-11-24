# Providers

Chaque provider implémente `JobProviderInterface` (`name()`, `isHealthy()`, `fetch()`), est
étiqueté `app.job_provider` et ne propage jamais d'exception : les erreurs réseau sont
journalisées et relevées par `FetchDiagnostics` pour le monitoring (`provider_run`).

| Provider | Nom CLI | Activation | Date de publication | État |
|---|---|---|---|---|
| `RsFeedProvider` | `rss` | au moins une `JOB_FEED_URL_*` | `pubDate` / `published` (`medium`), `updated` (`low`) | validé |
| `RemoteOkProvider` | `remoteok` | toujours | champ `date` (`high`) | validé |
| `SearxProvider` | `searxng` | toujours | `publishedDate` (`medium`) ou texte (`low`) | validé |
| `FranceTravailProvider` | `francetravail` | `FRANCE_TRAVAIL_ENABLED=true` | `dateCreation` (`high`) | **non validé** |
| `GreenhouseProvider` | `greenhouse` | `GREENHOUSE_BOARDS` non vide | inconnue (`updated_at` n'est pas une publication) | documentation vérifiée, **non testé sur une vraie réponse** |
| `LeverProvider` | `lever` | `LEVER_BOARDS` non vide | inconnue (`createdAt` non documenté) | documentation vérifiée, **non testé sur une vraie réponse** |
| `AshbyProvider` | `ashby` | `ASHBY_BOARDS` non vide | `publishedAt` (`medium`) | documentation vérifiée, **non testé sur une vraie réponse** |
| `EmailJobProvider` | `email` | `EMAIL_ENABLED=true` | toujours inconnue | **parsers non validés** — voir [EMAIL.md](EMAIL.md) |

## France Travail — API « Offres d'emploi » v2

> **Statut : implémenté avec des mocks, non validé contre la documentation officielle.**
> Désactivé par défaut. Ne l'activez qu'après avoir effectué les vérifications ci-dessous.

### Pourquoi « non validé »

- Le portail francetravail.io est rendu côté client : sa documentation n'a pas pu être lue
  lors de l'écriture du provider.
- La fiche [data.gouv.fr de l'API](https://www.data.gouv.fr/dataservices/api-offres-demploi)
  indique que l'API **n'est « plus exposée sur <www.francetravail.io> le temps d'une analyse des
  modalités de distribution et de réutilisation »**, à la suite d'usages automatisés massifs. L'accès
  est **restreint** et se demande via [francetravail.io/contact](https://francetravail.io/contact).
  Cette information provient d'une seule source, à recouper.
- Le quota annoncé sur cette fiche est de **10 appels par seconde** ; le provider reste à environ 6.

### À vérifier avant activation

1. **Accès** : obtenir un `client_id` / `client_secret` et confirmer que l'API est de nouveau exposée.
2. **Conditions de réutilisation** : lire les conditions et la licence ; confirmer qu'une collecte
   automatisée périodique pour un usage personnel est autorisée, et si une mention de source est requise.
3. **Authentification** : URL du jeton, `realm=/partenaire`, `scope` (`api_offresdemploiv2 o2dsoffre` par
   défaut ; un `application_<client_id>` peut être requis) — configurables par variables d'environnement.
4. **Recherche** : chemin `/offres/search` ; paramètres `motsCles`, `departement`, `range` (plafond de 150
   offres par page), `minCreationDate` et `maxCreationDate` (format `Y-m-d\TH:i:s\Z`, éventuellement
   obligatoires ensemble).
5. **Réponses** : codes 200, 206 (contenu partiel) et 204 (aucun résultat), en-tête `Content-Range`
   (`offres 0-149/1234` supposé), champs `resultats[]` lus, URL publique de repli d'une offre.
6. **Premier essai** : lancer `app:jobs:run --dry-run --provider=francetravail` et vérifier le résultat
   sur quelques offres réelles avant de désactiver le mode `--dry-run`.

Les tests (`tests/Unit/Provider/FranceTravailProviderTest.php`) utilisent des réponses simulées et la
fixture anonymisée `tests/Fixtures/france_travail/search_page.json` : ils prouvent le comportement du code,
pas la conformité à l'API réelle.

### Configuration (`app/.env.local`)

```dotenv
FRANCE_TRAVAIL_ENABLED=true
FRANCE_TRAVAIL_CLIENT_ID=votre-client-id
FRANCE_TRAVAIL_CLIENT_SECRET=votre-secret
FRANCE_TRAVAIL_KEYWORDS="php,symfony"   # une requête par mot-clé
FRANCE_TRAVAIL_DEPARTMENTS="13,75"      # une requête par département ; vide = toute la France
```

Réglages dans `jobscan.yaml` : `app.profile.france_travail_max_pages` (3), `_page_size` (150),
`_lookback_days` (7) et `_min_request_interval_ms` (150).

### Comportement

- **Collecte incrémentale** : demande les offres créées depuis la dernière exécution réussie (moins une
  heure de recouvrement) ; la première fois, depuis `lookback_days`. Les doublons sont éliminés par le
  pipeline (identifiant externe, URL).
- **Pagination** : pages de 150 au plus, arrêt à la fin des résultats, au total annoncé par `Content-Range`
  ou à `max_pages`.
- **Fiabilité** : jeton mis en cache jusqu'à son expiration ; un 401 déclenche un seul renouvellement ;
  429 et 5xx sont retentés avec backoff (en respectant `Retry-After`, plafonné à 10 s) ; les autres 4xx ne le
  sont pas. Un échec est visible dans `app:jobs:stats` (statut `failed` ou `partial`) sans bloquer les autres providers.
- **Données** : seules les informations fournies sont utilisées (entreprise et lieu absents restent inconnus) ;
  le contrat et le salaire, s'ils sont fournis, sont ajoutés à la description pour l'analyse. Les URLs non
  `http(s)` sont rejetées.
- **Secrets** : ni le secret client ni le jeton ne sont journalisés ; ils ne vont que dans `.env.local`.

## ATS — Greenhouse, Lever, Ashby

Trois providers lisent les **API publiques de publication d'offres** des job boards d'entreprises : aucune clé, aucun
scraping, hôtes fixes. Un provider est actif dès qu'au moins un board est configuré ; une liste vide le désactive (le run
l'enregistre « désactivé »).

> **Statut** : la documentation officielle de chacune a été lue lors de l'écriture, mais les providers n'ont été testés
> qu'avec des réponses simulées (fixtures anonymisées respectant les champs documentés), pas sur de vrais boards. Lancez
> d'abord `php bin/console app:jobs:run --dry-run --provider=greenhouse` (ou `lever`, `ashby`) sur un board connu.

### Configuration (`app/.env.local`)

```dotenv
GREENHOUSE_BOARDS="exemple=Exemple SAS,autre-entreprise"
LEVER_BOARDS="exemple=Exemple SAS"
LEVER_REGION=global          # ou eu
ASHBY_BOARDS="Exemple=Exemple SAS"
```

Chaque entrée est `identifiant` ou `identifiant=Nom de l'entreprise`. Le nom est celui que vous saisissez : les API ne le
fournissent pas toutes, et Jobscan ne le déduit **jamais** de l'identifiant (entreprise inconnue sinon). L'identifiant n'accepte que
lettres, chiffres, `.`, `_` et `-` (il est inséré dans l'URL : toute autre valeur est ignorée avec un avertissement).

| ATS | Où trouver l'identifiant | Endpoint |
|---|---|---|
| Greenhouse | `boards.greenhouse.io/{identifiant}` (« Job Board URL token ») | `GET boards-api.greenhouse.io/v1/boards/{id}/jobs?content=true` |
| Lever | `jobs.lever.co/{identifiant}` (`jobs.eu.lever.co` → `LEVER_REGION=eu`) | `GET api.lever.co/v0/postings/{id}?mode=json&skip=&limit=` |
| Ashby | `jobs.ashbyhq.com/{identifiant}` | `GET api.ashbyhq.com/posting-api/job-board/{id}?includeCompensation=true` |

Documentation : [Greenhouse](https://developers.greenhouse.io/job-board.html), [Lever](https://github.com/lever/postings-api),
[Ashby](https://developers.ashbyhq.com/docs/public-job-posting-api).

### Dates de publication

| ATS | Donnée | Traitement |
|---|---|---|
| Greenhouse | `updated_at` seulement dans la liste (`first_published` n'est que dans le détail) | **inconnue** : une mise à jour n'est pas une publication |
| Lever | `createdAt` absent de la documentation | **inconnue** (à confirmer sur une vraie réponse) |
| Ashby | `publishedAt`, documenté comme dernière publication | `medium` |

Un board contient souvent des offres anciennes : sans date fiable, elles ne sont pas écartées par le filtre d'ancienneté ;
seuls les filtres par mots-clés et le pré-score les trient.

### Comportement

- **Informations structurées** fournies par l'API (contrat, mode de travail, équipe, salaire, rémunération) : ajoutées à la
  description pour l'analyse, uniquement quand elles sont présentes. Le HTML est réduit à du texte (le contenu Greenhouse,
  échappé en entités, est décodé d'abord).
- **Identité** : Greenhouse `{board}:{id}`, Lever `id`, Ashby aucun identifiant documenté, donc l'URL de l'offre. Les doublons avec
  une autre source (URL canonique identique) sont détectés par le pipeline.
- **Pagination** : Greenhouse et Ashby renvoient toutes les offres en une réponse ; Lever pagine par `skip`/`limit` (maximum non
  documenté) jusqu'à une page vide, une page sans nouvelle offre, ou `app.profile.lever_max_pages`.
- **Fiabilité** : 429 et 5xx sont retentés (trois essais, backoff, `Retry-After` respecté, plafonné à 10 s) ; les autres 4xx (board
  inconnu) non. Un board en échec n'empêche pas les autres : le run est « partiel » dans `app:jobs:stats`.
- **Débit** : 200 ms minimum entre deux requêtes (`app.profile.ats_min_request_interval_ms`). Aucun quota n'est documenté.
- **Collecte incrémentale** : non applicable, aucune de ces API n'offre de filtre par date. Les offres déjà connues sont écartées par la
  déduplication ; une collecte toutes les 15 minutes reste raisonnable pour quelques boards.
- **Ashby** : les offres non listées (`isListed: false`, accessibles par lien direct seulement) sont ignorées.
