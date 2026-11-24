# Journal des modifications

Les changements notables de JOBSCAN sont documentés dans ce fichier à partir du
11 août 2026.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet
utilise le [versionnage sémantique](https://semver.org/lang/fr/). La dernière release
publiée est `v0.4.0` ; la prochaine sera constituée du contenu de la section
`Unreleased` lors de la création de son tag.

## [Unreleased]

### 2026-11-28

#### Added

- Profil candidat et profils de recherche : fichiers YAML locaux `candidate.yaml` et `search_profiles.yaml`
  (dossier `config/profile/local/`, ignoré par Git et exclu des images Docker, surchargeable par
  `JOBSCAN_PROFILE_DIR`), objets immuables (`CandidateProfile`, `SearchProfile`, `Skill`, `ScoringWeights`…),
  validation par schémas Symfony Config (clés inconnues rejetées) et contrôles croisés (compétence à la fois
  déclarée et exclue, CV inexistant, doublons…). Une compétence n'est jamais supposée : niveau et années
  restent inconnus tant qu'ils ne sont pas renseignés.
- Commandes `app:profile:init` (crée les fichiers depuis les exemples sans jamais écraser, en `0600`) et
  `app:profile:validate` (résumé sans contenu personnel, avertissement si les fichiers sont identiques aux exemples).
- Pondérations par défaut des critères de compatibilité (`app.profile.match_weights`) et seuil par défaut
  (`app.profile.default_min_match_score`), surchargeables par profil de recherche.
- Fichiers d'exemple fictifs versionnés et `docs/PROFILE.md`. Le profil n'est pas encore utilisé par le
  scoring (lots 2.2 et 2.3).

### 2026-11-27

#### Added

- `GreenhouseProvider`, `LeverProvider`, `AshbyProvider` : offres des job boards d'entreprises via les API
  publiques des ATS, pour une liste configurable de boards (`GREENHOUSE_BOARDS`, `LEVER_BOARDS`,
  `ASHBY_BOARDS`, `LEVER_REGION`). Socle commun `AbstractAtsProvider` : retry avec backoff, limitation de
  débit, validation stricte des identifiants de board, un board en échec n'empêche pas les autres.
  Dates de publication inconnues pour Greenhouse et Lever, confiance moyenne pour Ashby. Testés avec des
  réponses simulées (documentation officielle vérifiée, pas de test sur de vrais boards).

### 2026-11-26

#### Added

- `EmailJobProvider` : offres des emails d'alerte reçus dans une boîte IMAP dédiée (client
  `ImapEmailClient` via `directorytree/imapengine`, mot de passe d'application ou jeton OAuth2),
  parsers extensibles (`EmailParserInterface`, tag `app.email_parser`) pour LinkedIn, Indeed, HelloWork,
  Apec, France Travail et Welcome to the Jungle, plus un parser générique opt-in. Liens de suivi
  non suivis (pas de SSRF), URLs normalisées, date de publication toujours inconnue.
- Table `processed_email` : suivi persistant des emails traités. Un email n'est marqué traité qu'après
  ingestion réussie (`IngestionAwareProviderInterface::acknowledge`), jamais sur le statut lu/non lu.
- Fixtures d'emails anonymisées et reconstruites ; `docs/EMAIL.md`. **Désactivé par défaut ; parsers non validés sur de vrais emails.**

#### Changed

- `.env.test` vide `EXCLUDED_PLATFORM_DOMAINS` et `COLLECTION_BLOCKED_DOMAINS` : les tests ne dépendent plus des
  exclusions propres à la machine.

### 2026-11-25

#### Added

- `FranceTravailProvider` : API « Offres d'emploi » v2 de France Travail (OAuth2 client credentials
  avec jeton en cache, collecte incrémentale depuis le dernier succès, pagination, limitation de débit,
  retry avec backoff sur 429/5xx, renouvellement du jeton sur 401). **Désactivé par défaut
  (`FRANCE_TRAVAIL_ENABLED`) et non validé contre la documentation officielle** : voir `docs/PROVIDERS.md`.
- `docs/PROVIDERS.md` : tableau des providers et checklist de validation France Travail.

### 2026-11-24

#### Added

- Persistance avant analyse : `JobProcessor` ne fait plus d'appel LLM ; les offres sont
  enregistrées avec un statut d'analyse (`pending`, `done`, `fallback`, `failed`), un compteur
  de tentatives, une dernière erreur et une prochaine tentative (backoff exponentiel).
- `PendingAnalysisRunner` et commande `app:jobs:analyze` (`--limit`) : reprise des analyses en
  attente, heuristiques ou en échec. `app:jobs:run --no-analyze` collecte sans analyser.
- Modèle temporel : `firstSeenAt`, `lastSeenAt`, `analyzedAt`, `publicationDateSource` et
  `publicationDateConfidence` sur `Job` ; enums `PublicationDateSource`, `PublicationDateConfidence`.
- Table `job_source` : provenance multi-sources (URL, identifiant externe, date rapportée,
  dates de détection) ; backfill d'une source par offre existante.
- Table `provider_run` et `ProviderRunStatus` : suivi de chaque exécution de provider
  (succès, sans nouveauté, partiel, échec, désactivé) ; `FetchDiagnostics` relève les erreurs
  que les providers absorbent. Affiché par `app:jobs:stats`.
- `FreshnessScorer` : score de fraîcheur configurable (`app.profile.freshness_*`).
- `COLLECTION_BLOCKED_DOMAINS` : restriction de collecte distincte de l'exclusion métier.

#### Changed

- Déduplication : les doublons certains (URL, URL canonique, identifiant externe) mettent à
  jour l'offre existante ; une empreinte titre/entreprise/lieu identique ne fait plus supprimer
  l'offre, elle la signale comme doublon probable (jamais notifié). L'index `fingerprint` n'est
  plus unique.
- Les paramètres `ref` et `source` ne sont plus retirés des URLs canoniques.
- Une offre n'est notifiée qu'une fois son analyse définitive.
- `RunPipelineCommand` : l'analyse et la notification ont lieu après la collecte.
- `.env.example` : `DEFAULT_LLM_PROVIDER=ollama`.

#### Fixed

- Installation depuis un clone neuf : `cp .env.example .env` avant `composer install`,
  `typescript:build` avant PHPUnit (CI et `Dockerfile` corrigés).
- Documentation alignée sur le code (clients LLM, classes, roadmap).
- `makefile` : suppression de `COMPOSE_PROD`, qui référençait un fichier inexistant.
- `AIClient` : l'indisponibilité du LLM est réinitialisée à chaque lot d'analyses.

### 2026-09-02

#### Added

- `JobProviderInterface::isHealthy()` : sonde légère de disponibilité, appelée au
  démarrage de `app:jobs:run`. Un provider injoignable est ignoré (warning) sans
  annuler le run ; option `--skip-health-check` pour contourner.
- Filtre de bruit SearXNG extrait dans `SearxNoiseFilter`, avec listes
  `app.profile.searx_blocked_patterns` / `app.profile.searx_job_signals`
  configurables dans `jobscan.yaml` et couvertes par des tests unitaires.

- Commande `app:jobs:purge` : supprime les offres ingérées au-delà d'un seuil
  (`--older-than=30d` / `4w`), avec un mode `--dry-run`.
- Commande `app:jobs:export` : export CSV ou JSON des offres scorées, vers stdout
  ou un fichier (`--format`, `--min-score`, `--output`).

#### Changed

- Liste des signaux emploi SearXNG élargie (engineer, ingénieur, poste,
  opportunité, contractor, lead, architect…) pour réduire les faux négatifs.

### 2026-08-11

#### Changed

- Messages Telegram envoyés en HTML avec échappement des données provenant des offres.

#### Fixed

- Arrêt immédiat des retries Telegram sur les erreurs HTTP définitives.
- Prise en compte de `retry_after` lors d'une limitation de débit Telegram.

## [0.4.0] - 2026-08-11

### 2026-08-11

#### Added

- Pagination configurable des résultats SearXNG avec arrêt sur une page vide.
- Tests d'intégration de `JobProcessor` sur une base SQLite isolée.
- Retry avec backoff exponentiel sur les erreurs transitoires des providers LLM.

#### Changed

- Temporisation configurable entre les lots de requêtes SearXNG concurrents.
- Cache SearXNG séparé pour chaque requête et chaque page.
- Persistance Doctrine groupée par lots configurables.
- Envoi des notifications Telegram après la persistance réussie de chaque lot.
- Séparation des tests PHPUnit en suites unitaires et d'intégration exécutables indépendamment.

#### Fixed

- Identification des offres RSS à partir du domaine de leur flux d'origine.

## [0.3.0] - 2026-08-11

### 2026-08-11

#### Added

- Déduplication avancée par URL canonique et empreinte métier.
- Persistance de l'analyse IA, du détail du score, de l'entreprise, de la localisation
  et de la date de publication.
- Provider RemoteOK basé sur son API JSON officielle.
- Cache, quota et exécution concurrente des recherches SearXNG.
- Retry exponentiel et confirmation de livraison pour les notifications Telegram.
- Commandes Make pour charger et inspecter le modèle LM Studio.
- Options `--dry-run`, `--provider` et `--reset` pour piloter le pipeline.
- Résumé détaillé de chaque exécution et commande `app:jobs:stats`.
- Tests unitaires des providers, notifications, identités, commandes et fallbacks IA.

#### Changed

- Sélection du provider LLM via `DEFAULT_LLM_PROVIDER`.
- Centralisation des seuils, pondérations et limites sous `app.profile.*`.
- Calcul déterministe de la récence depuis la date de publication.
- Utilisation explicite de PHP 8.4 pour les hooks et outils du projet.
- Qwen3 utilise le mode `/no_think` afin de produire directement le JSON attendu.
- Chaque commande exposée par `make help` inclut désormais un exemple.

#### Fixed

- Migration de rattrapage des identités canoniques déjà présentes en base.
- Prévention des violations d'unicité entre URL brute et URL canonique.
- Poursuite des recherches SearXNG lorsqu'une requête échoue.
- Arrêt des avertissements IA répétés après une indisponibilité confirmée du provider.
- Résolution de LM Studio depuis l'hôte et depuis Docker.
- Gestion des URLs de flux RSS optionnelles ou inaccessibles.
- Protection du reset de la table `job` en production et avec le mode dry-run.
