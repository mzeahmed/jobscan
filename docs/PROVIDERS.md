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
