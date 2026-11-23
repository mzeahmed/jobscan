# Journal des modifications

Les changements notables de JOBSCAN sont documentés dans ce fichier à partir du
11 août 2026.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet
utilise le [versionnage sémantique](https://semver.org/lang/fr/). La dernière release
publiée est `v0.4.0` ; la prochaine sera constituée du contenu de la section
`Unreleased` lors de la création de son tag.

## [Unreleased]

### 2026-11-23

#### Added

- `TextMatcher` : recherche de mots-clés par mots entiers, insensible à la casse et
  aux accents.
- Jugement de pertinence par le LLM (`primary_language`, `relevance`, `location_ok`),
  intégré au score via `app.profile.scoring.compute.relevance` (bonus/malus de
  pertinence, malus langage hors cible et localisation hors cible).

- Profil entièrement configurable par env : `TARGET_LANGUAGES`, `PROFILE_DESCRIPTION`
  (prompt LLM) et pondérations du scoring `SCORING_PRESCORE_KEYWORDS`,
  `SCORING_TITLE_KEYWORDS`, `SCORING_STACK_KEYWORDS`, `SCORING_DESCRIPTION_KEYWORDS`,
  `SCORING_NEGATIVE_KEYWORDS` (format `mot:points`). Défauts = profil PHP/Symfony/WordPress.

#### Changed

- Mots-clés pénalisants par défaut étendus à `stagiaire` et `alternant` (le matching par
  mots entiers ne les couvre plus via `stage`/`alternance`).
- `Scoring`, `JobProcessor` et le fallback heuristique d'`AIClient` n'utilisent plus
  `str_contains` : « api » ne matche plus « capital », « mission » plus « commission ».
- Le LLM reçoit le titre, l'entreprise et le lieu en plus de la description.
- Clé de cache IA versionnée (`ai_v2_`) : les analyses en cache sont recalculées.

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
