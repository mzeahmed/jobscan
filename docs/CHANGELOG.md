# Journal des modifications

Les changements notables de JOBSCAN sont documentés dans ce fichier à partir du
11 août 2026.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet
utilise le [versionnage sémantique](https://semver.org/lang/fr/). La dernière release
publiée est `v0.4.0` ; la prochaine sera constituée du contenu de la section
`Unreleased` lors de la création de son tag.

## [Unreleased]

### 2026-12-14

#### Changed

- Interface : refonte visuelle des pages de suivi dans le système de design existant. Connexion en carte centrée avec logo ; messages en bannières ; liste des candidatures avec filtres en pastilles, statuts colorés et
  encart « relances à faire » ; détail en deux colonnes (statut et informations, historique en chronologie) ; Analytics avec indicateurs, barres de progression (élément natif `<progress>`, compatible avec la CSP) et tableaux
  en panneaux ; actions des offres sous leur carte, « Suivre » mis en avant et « Ignorer » en rouge au survol. Compteurs de la barre du haut réservés à la liste des offres.
- Variantes de boutons (`btn--primary`, `btn--ghost`) à spécificité renforcée : Bootstrap, chargé après le style maison, les rendait transparentes.

#### Fixed

- L'historique d'une candidature saisie après coup (brouillon créé aujourd'hui, envoi daté d'il y a dix jours) était trié par date et présentait « Brouillon » comme dernière étape, donc comme statut actuel ; il suit désormais l'ordre
  réel des statuts.

### 2026-12-13

#### Fixed

- Derrière Traefik (pile de développement), les redirections de l'application sortaient en `http://` (connexion, déconnexion, `/job` → `/login`), ce qui menait à une erreur 404 sur le port HTTPS 8443. `TRUSTED_PROXIES=REMOTE_ADDR` est désormais
  fixé dans `docker-compose.yml` (et, par défaut, dans `docker-compose.prod.yml`) : l'application relaie le schéma, l'hôte et le port de Traefik, et le cookie de session est « secure ». Tests de non-régression ; comportement par défaut
  inchangé hors Docker (les en-têtes `X-Forwarded-*` restent ignorés sans proxy de confiance).

### 2026-12-12

#### Added

- `docs/SCHEDULER.md` : évaluation de Symfony Scheduler (et Messenger) fondée sur un prototype jetable : cadence, exécution en série des tâches lentes, échec sans rejeu, rattrapage des exécutions
  manquées (stateful), dépendance supplémentaire pour les horaires cron, coût mémoire. Décision : ne pas adopter pour l'instant ; conditions de révision et esquisse d'adoption.

### 2026-12-11

#### Added

- Monitoring avancé : `app:health:check` (`--format=json`, `--strict`, `--alert`) contrôle base, migrations, chaque source d'offres, file d'analyses, part d'analyses de repli (LLM), notifications et
  rappels en retard, disque, profils ; sans appel réseau ni LLM ; seuils `app.health.*`. Alerte Telegram une seule fois par changement d'état (empreinte dans `var/health.state`, mémorisée seulement si Telegram
  a reçu le message). Un contrôle qui plante est signalé lisiblement sans empêcher les autres.
- Rapports : `app:report:weekly` (`--days`, `--output`, `--telegram`) : collecte, candidatures de la période, relances à faire, cinq meilleures offres à trier, santé ; résumé Telegram sans donnée de recruteur.
- Planification Docker : contrôle de santé toutes les heures avec alerte, rapport le dimanche à 18 h 30. `docs/MONITORING.md`.

### 2026-12-10

#### Added

- Analytics : `app:analytics:report` (`--since=30d|12w|all`, `--format=json`) et page `/analytics` : parcours des candidatures envoyées lu dans l'historique (réponses, entretiens, propositions, délai médian
  de première réponse), répartitions par source, version de CV, profil de recherche et canal, rendement des sources (collectées, compatibles, notifiées, suivies, postulées ; doublons exclus). Un pourcentage
  n'apparaît qu'à partir de 5 candidatures. Calcul local, sans nouvelle table. `docs/ANALYTICS.md`.

### 2026-12-09

#### Added

- Déploiement Docker autonome (`docker-compose.prod.yml`, `make prod-up`, `make prod-up-workers`) : image multi-étapes (`dev`, `build`, `prod`) avec code figé, dépendances sans `--dev`, ressources compilées et
  OPcache de production ; utilisateur sans privilège, racine en lecture seule, aucune capacité Linux ; migrations au démarrage ; services optionnels `cron` (supercronic épinglé par empreinte, tâches sous `flock`)
  et `telegram` ; web publié uniquement sur `127.0.0.1`. Pile testée de bout en bout dans un projet Docker isolé. `docs/DEPLOY.md`.
- Extension PHP `pcntl` dans l'image (arrêt propre des services de longue durée).

#### Changed

- Le conteneur de développement tourne sous l'identifiant de l'utilisateur (`HOST_UID`/`HOST_GID`) au lieu de root : plus de fichiers appartenant à root dans `app/` (cause du `chmod -R 777 app/var` et des
  `composer install` impossibles depuis l'hôte).
- `docker/nginx/default.conf` : `server_name _`, `server_tokens off`, corps de requête limité à 1 Mo.

#### Security

- `.dockerignore` n'excluait ni `app/.env` ni `app/.env.local` : le `COPY` du Dockerfile les embarquait dans l'image. Ils sont exclus, ainsi que `app/tests` ; l'image part du modèle `.env.example`.
- Tableau de bord Traefik (sans authentification) publié sur `127.0.0.1:9080` au lieu de toutes les interfaces.
- Documentation et exemple de `TRUSTED_PROXIES` : l'adresse exacte du proxy remplace `private_ranges` (CVE-2026-121619).

#### Fixed

- `app:telegram:poll` plantait sans l'extension `pcntl` (constantes `SIGINT`/`SIGTERM` indéfinies), donc dans l'image PHP officielle ; il fonctionne désormais sans, et l'image l'inclut.

### 2026-12-08

#### Added

- Providers ATS **Recruitee**, **Workable** et **SmartRecruiters** (`RECRUITEE_BOARDS`, `WORKABLE_BOARDS`, `SMARTRECRUITERS_BOARDS`), désactivés tant qu'aucun board n'est configuré. Forme des réponses relevée sur de vraies API
  publiques puis providers exécutés contre elles (lecture seule). Recruitee et Workable ne sont pas documentés par leur éditeur ; SmartRecruiters ne fournit la description que par offre (plafonnée par
  `app.profile.smartrecruiters_max_details`, 30 par board). Dates de publication de confiance moyenne ; nom d'entreprise de l'API en repli de celui configuré. Voir `docs/PROVIDERS.md`.

#### Changed

- `labelled()` remonté dans `AbstractAtsProvider` (supprimé des copies d'Ashby et Lever).

### 2026-12-07

#### Added

- Rappels de relance : `app:reminders:send` (`--dry-run`, `--limit`) envoie par Telegram un rappel pour chaque relance planifiée dont la date est atteinte, une seule fois par date, sans
  rappel pour un brouillon ni une candidature terminée. Boutons ✅ Relancé et ⏰ +3 j / +7 j. Message sans contact ni nom de recruteur. Relances dues signalées dans l'interface web et `app:applications:list`.
- Migration non destructive (`job_application.follow_up_reminded_at`). `docs/REMINDERS.md`.

### 2026-12-06

#### Added

- Telegram interactif : boutons sur les notifications immédiates (Suivre, Garder, Ignorer, Annuler, J'ai postulé) et commandes `/offres`, `/candidatures`, `/aide`. `app:telegram:poll` (`--once`, `--max-seconds`)
  interroge Telegram : aucun port exposé. Position de lecture mémorisée (`var/telegram.offset`). `docs/TELEGRAM.md`.
- Sécurité : seul le chat configuré peut agir (chat privé : son titulaire ; groupe : liste `TELEGRAM_ALLOWED_USER_IDS` obligatoire), mises à jour étrangères ignorées sans réponse, données des boutons validées strictement.

#### Fixed

- Le jeton du bot Telegram pouvait apparaître dans les journaux (message d'erreur réseau contenant l'URL de l'API) : il est désormais masqué.

### 2026-12-05

#### Added

- Interface de suivi sécurisée : `/applications` (liste filtrable, compteurs) et détail d'une candidature (statut avec transitions autorisées, recruteur, contact, canal, CV, relance, message envoyé, notes, historique) ;
  sur `/job` : suivre, sauvegarder, ignorer, archiver une offre, filtre « Décision ». Écritures en POST uniquement, avec les mêmes règles de validation que les commandes.
- Sécurité : `symfony/security-bundle` et `symfony/rate-limiter` ; compte unique configuré par hash (`app:ui:hash-password`, `JOBSCAN_UI_PASSWORD_HASH`) ; jeton CSRF par action et par objet ;
  limitation à 5 tentatives de connexion par 15 minutes ; cookie de session `HttpOnly`/`SameSite=Lax`/`Secure` ; CSP à nonce, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `no-store` ;
  `TRUSTED_PROXIES` optionnel derrière un proxy inverse. `docs/UI.md`.

#### Changed

- **`/job` exige désormais une connexion** (il était public). Tant qu'aucun mot de passe n'est configuré, personne ne peut se connecter.
- Les offres ignorées et archivées sont masquées de la liste par défaut.

#### Fixed

- Une URL d'annonce `javascript:` était insérée telle quelle dans un `href` de la liste des offres : seules les URL `http(s)` sont désormais cliquables.

### 2026-12-04

#### Added

- Application Assistant : `app:assistant:draft` prépare un brouillon de message de candidature à partir des seuls faits déclarés dans le profil et rencontrés par l'offre. Brouillon déterministe par
  défaut ; `--llm` fait rédiger le texte par le LLM, qui n'est retenu qu'après contrôle (chiffres, compétences, liens, consignes), sinon repli sans LLM avec la raison.
- Confidentialité : un moteur LLM distant ne reçoit aucune donnée du profil sans `ASSISTANT_ALLOW_REMOTE_LLM=true` ; jamais nom, email, téléphone, salaire, fichier de CV ni texte brut de l'annonce.
  Reconnaissance des moteurs locaux (localhost, réseau privé, hôte Docker) ; une adresse publique est « distante ». `docs/ASSISTANT.md`.

### 2026-12-03

#### Added

- Gestion des CV : `app:cv:list` (état du fichier sans le lire, profils de recherche utilisateurs, candidatures envoyées et réponses par version), `app:cv:recommend` (CV du profil de recherche
  le plus pertinent, sans rien deviner), `app:applications:cv` (associer, changer ou retirer). `app:applications:track` suggère le CV recommandé sans l'appliquer. `docs/CV.md`.

### 2026-12-02

#### Added

- Suivi des candidatures : entités `Application` (statut, dates d'envoi, de réponse et de dernier contact, version de CV, recruteur, canal, message envoyé, notes, relance) et historique
  des changements de statut ; transitions validées (statuts finaux, chronologie), sans rien envoyer ni déduire.
- Décision sur une offre (`saved`, `ignored`, `archived`) ; une offre décidée n'est plus notifiée.
- Commandes `app:applications:track|status|list` et `app:jobs:decide`.
- **Protection contre la purge** : `app:jobs:purge` épargne les offres avec candidature ou décision utilisateur et indique combien ont été conservées.
- Migration non destructive (`job_application`, `job_application_status_change`, `job.user_state`). `docs/APPLICATIONS.md`.

### 2026-12-01

#### Added

- Notifications Telegram fondées sur le matching, sur trois niveaux : opportunité prioritaire (immédiate, sonore), offre intéressante (immédiate silencieuse, en récapitulatif
  ou désactivée, selon `app.notifications.interesting_mode`), offre secondaire (récapitulatif périodique). Seuils et modes configurables (`app.notifications.*`). Seules
  les offres compatibles sont notifiées : la fraîcheur seule ne rend jamais une offre incompatible notifiable, et une extraction heuristique ne peut être que « intéressante ».
- Messages enrichis (poste, entreprise, lieu, contrat, salaire, télétravail, date de publication distinguée de la détection, ancienneté, compatibilité, fraîcheur, priorité,
  profil et CV recommandés, principales raisons, lien), limités aux informations disponibles, échappés et bornés sous la limite de Telegram.
- `notification_log` (migration non destructive) : une offre est notifiée au plus une fois, immédiatement ou en récapitulatif ; respect de `job.notified_at`.
- `app:notifications:digest` (`--dry-run`, `--max-items`, `--no-catchup`) : récapitulatif borné, marquage des seules offres reprises, rien de marqué en cas d'échec d'envoi, rattrapage
  des notifications immédiates manquées, limite d'ancienneté (`app.notifications.max_age_days`).
- `TelegramNotifier::send()` accepte des notifications silencieuses et la désactivation de l'aperçu des liens.
- `docs/NOTIFICATIONS.md`.

#### Fixed

- Échappement Telegram : les apostrophes des titres produisaient `&apos;`, entité que Telegram ne reconnaît pas ; elles produisent maintenant `&#039;` (y compris pour l'ancien message).

### 2026-11-30

#### Added

- Matching multi-profils : compatibilité (`MatchScorer`, 0–100), fraîcheur et priorité (`PriorityCalculator`, 75 % / 25 %), entièrement déterministes et
  calculés en PHP à partir des exigences extraites et du profil déclaré. Critères éliminatoires évalués indépendamment du score ; une information inconnue
  ou sans objet sort du calcul (elle n'est jamais une incompatibilité) et réduit une **couverture** qui conditionne le verdict (`eligible`, `below_threshold`,
  `insufficient_data`, `eliminated`). Garde-fou : la fraîcheur seule ne rend jamais prioritaire une offre incompatible.
- Résultats conservés par offre et par profil de recherche (`job_match`, migration non destructive), explicables (critères, compétences correspondantes ou
  manquantes, éliminations, inconnus, arguments tirés du profil déclaré, CV recommandé), marqués d'une empreinte (profil + règles) : **recalculables sans appel au LLM**
  et recalculés seulement s'ils sont périmés.
- Rapprochement des compétences par alias (`app.profile.skill_aliases`), en ignorant casse, accents, ponctuation et numéros de version.
- Commandes `app:jobs:match` (`--all`, `--priorities`), `app:jobs:priority` (`--search-profile`, `--all`, `--limit`) et `app:jobs:explain` (`--recompute`).
- Le matching s'exécute après chaque analyse et ré-extraction ; son échec n'empêche jamais l'analyse. La purge et la réinitialisation des offres suppriment leurs résultats.
- `docs/MATCHING.md`.

#### Fixed

- Tests : `.env.test` ne dépend plus du dossier de profil de la machine (`JOBSCAN_PROFILE_DIR` hermétique).

### 2026-11-29

#### Added

- Extraction IA structurée (`JobRequirements`) : compétences exigées / appréciées / citées, séniorité, années d'expérience,
  langues, mode de travail, types de contrat, rémunération et lieux, extraits **indépendamment du profil candidat** par un seul
  appel LLM, validés strictement (`RequirementsValidator`) et stockés dans `job.requirements` avec leur version de schéma
  (`job.extraction_version`). Migration non destructive (deux colonnes nullables).
- Ancrage dans le texte : toute compétence, langue, contrat, mode de travail, montant ou lieu absent de l'annonce est écarté avec un
  avertissement. Une information absente reste inconnue, jamais « non ».
- Durcissement contre l'injection de prompt : règles de sécurité ajoutées par le code, annonce entre balises à identifiant aléatoire,
  neutralisation des consignes détectées (retirées avant l'envoi et avant l'ancrage), sortie du modèle jamais interprétée.
- `app:jobs:analyze --outdated` : ré-extraction explicite des offres dont le schéma est périmé, sans notification et sans jamais
  remplacer une analyse LLM par un repli heuristique. `app:jobs:stats` signale les offres à ré-extraire.
- Types de contrat `cdd`, `alternance` et `stage` (`ContractType`), utilisables dans les profils.
- `docs/EXTRACTION.md`.

#### Changed

- `AIClient::extract()` est le point d'entrée ; `analyze()` en reste la vue historique pour le scoring existant. Prompt
  `app.ai_system_prompt` remplacé par le schéma d'extraction ; texte envoyé au modèle porté à 6000 caractères
  (`app.profile.ai_max_input_chars`) et réponse jusqu'à 2048 jetons.
- Les rémunérations ne sont plus converties en euros : une offre en devise étrangère n'obtient plus le bonus de budget du scoring.
- Clé de cache IA : schéma + prompt + texte (les anciennes entrées ne sont plus lues).

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
