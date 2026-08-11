# Journal des modifications

Les changements notables de JOBSCAN sont documentés dans ce fichier à partir du
11 août 2026.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet
utilise le [versionnage sémantique](https://semver.org/lang/fr/). La dernière release
publiée est `v0.4.0` ; la prochaine sera constituée du contenu de la section
`Unreleased` lors de la création de son tag.

## [Unreleased]

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
