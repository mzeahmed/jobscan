# JOBSCAN — Roadmap

> Les contributions sont les bienvenues à chaque phase. Consultez [CONTRIBUTING.md](CONTRIBUTING.md) pour démarrer.

---

## Vision

JOBSCAN est un pipeline d'intelligence emploi, local et sans coût externe.

Il remplace la navigation manuelle sur les job boards par un pipeline automatisé : ingestion d'offres brutes depuis RSS et recherche web, filtrage du bruit, analyse de chaque offre par un LLM local, scoring selon un profil configuré, et envoi des meilleures opportunités sur Telegram.

**Contraintes de conception non négociables :**
- Aucune dépendance à une API externe payante
- Fonctionne entièrement sur la machine du développeur
- Un seul fichier YAML pour reconfigurer pour n'importe quel profil technique
- Pipeline en ligne de commande Symfony — aucun dashboard requis pour être utile

---

## Architecture

```
Providers (RSS + SearXNG)
    → JobProcessor (filtre + dédup)
        → AIClient (LM Studio / LLM local)
            → ScoringService
                → JobRepository (SQLite)
                    → NotificationService (Telegram)
```

Fichiers clés :
| Fichier | Rôle |
|---------|------|
| `src/Service/Provider/JobProviderInterface.php` | Contrat de tous les providers |
| `src/Service/Processor/JobProcessor.php` | Déduplication, filtrage, orchestration |
| `src/Service/AI/AIClient.php` | API LM Studio + fallback heuristique |
| `src/Service/Scoring/ScoringService.php` | Calcul du score |
| `src/Service/Notification/TelegramNotifier.php` | Alerte Telegram |
| `config/packages/jobscan.yaml` | Toute la configuration métier |

---

## Phase 1 — MVP & Stabilisation ✅

Pipeline fonctionnel, reproductible en local.

- [x] Agrégation d'offres via flux RSS (`RsFeedProvider`)
- [x] Agrégation d'offres via SearXNG (`SearxProvider`)
- [x] Filtre par mots-clés et stack technique
- [x] Pré-scoring heuristique (sans IA)
- [x] Analyse IA locale via LM Studio (API compatible OpenAI)
- [x] Scoring final sur 100 avec breakdown
- [x] Persistance SQLite via Doctrine
- [x] Alerte Telegram pour les meilleures offres
- [x] Configuration centralisée dans `jobscan.yaml`
- [x] Hook pre-push avec PHPStan
- [x] Tests unitaires sur `ScoringService`
- [ ] Tests d'intégration sur `JobProcessor` — voir [Phase 3, #17](#17--tests-dintégration--jobprocessor)
- [ ] CI GitHub Actions (lint + PHPStan) — voir [Phase 3, #18](#18--github-actions-ci)

---

## Phase 2 — Qualité du signal

**Objectif :** Réduire le bruit, améliorer la précision des offres remontées.

---

### #1 — Filtre sur l'ancienneté des offres `good first issue` `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Les offres de plus de 30 jours doivent être écartées avant tout traitement. Actuellement, le pipeline traite toutes les offres récupérées sans tenir compte de leur date de publication.

**Critères d'acceptation :**
- Les offres dont la date de publication dépasse un seuil configurable sont ignorées dans `JobProcessor`
- Seuil par défaut : 30 jours, configurable via `jobscan.yaml`
- Les offres ignorées sont loguées au niveau `debug` avec leur ancienneté

**Pistes techniques :**
- Parser la date depuis `JobDTO` avant de passer à `AIClient`
- Ajouter `max_job_age_days: 30` dans `config/packages/jobscan.yaml`
- Le filtre appartient à `JobProcessor::process()`, avant l'appel IA

---

### #2 — Déduplication avancée `enhancement`

🟡 **Difficulté :** Intermédiaire

**Description :** La déduplication actuelle se base uniquement sur l'URL. Une même offre publiée sur plusieurs boards avec des URLs différentes (ex. : LinkedIn + RemoteOK) est traitée et notifiée deux fois.

**Critères d'acceptation :**
- La détection de doublons utilise la similarité du titre + le domaine source, pas seulement l'URL
- Un seuil de similarité configurable (ex. : 80 %) permet d'ignorer les doublons probables
- Aucun faux positif sur des offres avec des titres similaires mais des entreprises différentes

**Pistes techniques :**
- Utiliser `similar_text()` ou la distance de Levenshtein sur des titres normalisés
- Ajouter `findSimilar(string $title, string $company): ?Job` dans `JobRepository`
- Normaliser avant comparaison : minuscules, suppression de la ponctuation et des mots courants

---

### #3 — Label de source RSS par domaine `good first issue` `bug`

🟢 **Difficulté :** Bonne première contribution

**Description :** Toutes les offres RSS ont `source = 'feed'` quel que soit le flux d'origine. Impossible de distinguer `remoteok.com` de `reddit.com` dans les résultats.

**Critères d'acceptation :**
- `JobDTO->source` reflète le domaine du flux d'origine (ex. : `remoteok`, `reddit`, `linkedin`)
- Les offres existantes ne sont pas affectées (pas de migration nécessaire)
- L'extraction gère les URLs malformées sans erreur

**Pistes techniques :**
- `parse_url($feedUrl, PHP_URL_HOST)` + suppression du `www.`
- Modification dans `RsFeedProvider::fetch()`

---

### #4 — Pagination SearXNG `good first issue` `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** SearXNG est appelé sans le paramètre `pageno`, ce qui plafonne les résultats à ~10 par requête. Avec 7 requêtes configurées, le pipeline voit au maximum 70 offres par run.

**Critères d'acceptation :**
- Chaque requête SearXNG récupère jusqu'à N pages (configurable, défaut : 2)
- Un plafond de résultats par requête est respecté pour ne pas surcharger l'instance
- Nouvelle clé de config dans `jobscan.yaml` : `searx_max_pages: 2`

**Pistes techniques :**
- SearXNG accepte `pageno=1`, `pageno=2` comme paramètres de requête
- Implémenter dans `SearxProvider::fetchQuery()`, boucle de 1 à `$maxPages`
- Arrêt anticipé si une page retourne 0 résultats

---

### #5 — Rate limiting entre les requêtes SearXNG `good first issue` `bug`

🟢 **Difficulté :** Bonne première contribution

**Description :** 7 requêtes sont envoyées en rafale à SearXNG à chaque run. Cela peut saturer une instance locale ou déclencher un rate limiting.

**Critères d'acceptation :**
- Un délai configurable est inséré entre chaque requête (défaut : 500 ms)
- Clé de config : `searx_query_delay_ms: 500` dans `jobscan.yaml`
- Un délai de 0 désactive le throttling

**Pistes techniques :**
- `usleep($delayMs * 1000)` entre chaque itération dans `SearxProvider`

---

### #6 — Support multi-modèles LM Studio `enhancement`

🟡 **Difficulté :** Intermédiaire

**Description :** `AIClient` utilise un seul modèle défini par `AI_MODEL`. Il n'est pas possible de configurer un modèle par type d'offre, ni de comparer deux modèles.

**Critères d'acceptation :**
- `jobscan.yaml` accepte une clé `ai_model` qui surcharge la variable d'env `AI_MODEL`
- Un modèle de secours peut être configuré si le modèle principal est indisponible
- Changer de modèle ne nécessite aucune modification de code

**Pistes techniques :**
- Injecter le nom du modèle via un paramètre Symfony (`%app.ai_model%`)
- La variable `AI_MODEL` reste la valeur par défaut si `ai_model` n'est pas défini dans le YAML

---

### #7 — Normalisation des sorties IA `enhancement`

🟡 **Difficulté :** Intermédiaire

**Description :** L'analyse IA retourne des valeurs texte libres pour `contract`, `remote` et `budget`. Le scoring et le filtrage en aval reposent sur des correspondances de chaînes inconsistantes (`"remote"`, `"full remote"`, `"yes"`, etc.).

**Critères d'acceptation :**
- `contract` normalisé en enum : `freelance | cdi | cdd | unknown`
- `remote` normalisé en : `full | partial | none | unknown`
- `budget` parsé en entier (taux journalier) ou `null`
- Le fallback heuristique retourne également des valeurs normalisées

**Pistes techniques :**
- Ajouter un objet valeur `AiAnalysisResult` avec des propriétés typées
- La logique de normalisation appartient à `AIClient`, pas à `ScoringService`

---

### #8 — Enrichissement du scoring par séniorité et budget `enhancement`

🟡 **Difficulté :** Intermédiaire

**Description :** Le scoring ignore le niveau de séniorité et le budget. Une offre junior full remote score pareil qu'une offre senior. Une offre à 200 €/jour rank pareil qu'une à 700 €/jour.

**Critères d'acceptation :**
- `ScoringService` applique un bonus configurable pour la séniorité détectée (`senior` : +10, `lead` : +15)
- `ScoringService` applique un bonus pour un TJM détecté au-dessus d'un seuil configurable
- Les nouvelles règles sont documentées dans la section scoring de `jobscan.yaml`

**Pistes techniques :**
- Nécessite le ticket #7 (sorties IA typées) pour être fiable
- Ajouter `scoring_weights.seniority` et `scoring_weights.min_daily_rate` dans `jobscan.yaml`

---

## Phase 3 — Robustesse & Tests

**Objectif :** Rendre le pipeline fiable et sûr à faire évoluer.

---

### #9 — Retry avec backoff exponentiel sur l'IA `bug`

🟡 **Difficulté :** Intermédiaire

**Description :** Une erreur réseau ou un timeout LM Studio tombe silencieusement dans le fallback heuristique sans aucune tentative de relance. Une erreur transitoire fait sauter l'IA pour toute l'offre.

**Critères d'acceptation :**
- `AIClient` effectue jusqu'à 3 tentatives en cas d'erreur réseau ou de timeout
- Délais : 1 s, 2 s, 4 s (exponentiel)
- Après 3 échecs, le fallback heuristique est déclenché et logué en `warning`
- Le nombre maximum de tentatives est configurable

**Pistes techniques :**
- Encapsuler l'appel HTTP dans une boucle avec `sleep()` entre les tentatives
- Capturer `\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface`

---

### #10 — Circuit breaker sur LM Studio `bug` `enhancement`

🔴 **Difficulté :** Avancé

**Description :** Si LM Studio est indisponible, chaque offre du pipeline tente un appel avec un timeout de 120 s avant le fallback. Pour 50 offres, le pipeline est bloqué des heures.

**Critères d'acceptation :**
- Après N échecs consécutifs (défaut : 3), le circuit s'ouvre
- En état ouvert, toutes les offres vont directement au fallback heuristique (aucun appel HTTP)
- Le circuit se réinitialise après une période de refroidissement configurable (défaut : 5 min)
- Les transitions d'état sont loguées en `warning`

**Pistes techniques :**
- Implémenter une classe `CircuitBreaker` avec les états : `closed`, `open`, `half-open`
- Persister l'état en mémoire (par run) ou dans `var/circuit_breaker.json` pour la persistance inter-runs
- Injecter dans `AIClient` comme dépendance

---

### #11 — Optimisation des insertions en batch `good first issue` `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** `JobRepository::save()` appelle `flush()` après chaque offre. Pour 50 nouvelles offres, cela représente 50 transactions SQLite séparées.

**Critères d'acceptation :**
- Les offres sont flushées par lots de 20 (configurable)
- Un flush final est appelé après la boucle pour persister le dernier lot partiel
- L'amélioration de performance est mesurable sur des runs de 50+ offres

**Pistes techniques :**
- Ajouter un compteur `$batchSize` dans `JobProcessor` ou `JobRepository`
- Appeler `$entityManager->flush()` toutes les 20 itérations, puis une fois après la boucle
- `$entityManager->clear()` après chaque flush pour libérer la mémoire

---

### #12 — Déclarations `.PHONY` dans le Makefile `good first issue` `refactor`

🟢 **Difficulté :** Bonne première contribution

**Description :** Les targets du Makefile (`up`, `logs`, `bash`, `migrate`, etc.) ne sont pas déclarées `.PHONY`. Si un fichier portant le même nom qu'une target existe, Make peut ignorer silencieusement la target.

**Critères d'acceptation :**
- Toutes les targets non-fichiers du `makefile` sont listées dans `.PHONY`
- `touch up && make up` exécute toujours la target correctement

**Pistes techniques :**
- Ajouter en tête de `makefile` : `.PHONY: help build up down logs bash migrate run-pipeline alerts fix-perms stan pint pintf setup`

---

### #13 — Sécurisation du Dockerfile `refactor`

🟡 **Difficulté :** Intermédiaire

**Description :** Le Dockerfile actuel tourne en root, embarque les dépendances de dev, et n'a pas de `.dockerignore`. Cela gonfle la taille de l'image et élargit la surface d'attaque inutilement.

**Critères d'acceptation :**
- Le container tourne sous un utilisateur non-root (`app`)
- Build multi-stage : stage `builder` installe les dépendances, stage `final` ne copie que `vendor/` et le code app
- `.dockerignore` exclut `.git/`, `tests/`, `var/`, `node_modules/`
- L'image finale est plus légère que l'image actuelle

**Pistes techniques :**
- Stage 1 : `FROM php:8.3-cli AS builder` — lancer `composer install --no-dev`
- Stage 2 : `FROM php:8.3-cli` — `COPY --from=builder /app/vendor ./vendor`
- `RUN useradd -u 1000 -m app && chown -R app:app /app`
- `USER app` comme dernière instruction avant `CMD`

---

### #14 — Interface de healthcheck des providers `enhancement`

🟡 **Difficulté :** Intermédiaire

**Description :** Il n'existe aucun moyen de vérifier que SearXNG ou un flux RSS est accessible avant de lancer le pipeline. Une URL SearXNG mal configurée échoue silencieusement en cours de run.

**Critères d'acceptation :**
- `JobProviderInterface` gagne une méthode `isHealthy(): bool`
- `RunPipelineCommand` vérifie tous les providers au démarrage et avertit (sans avorter) si l'un est indisponible
- Les providers indisponibles sont ignorés avec un message de log explicite

**Pistes techniques :**
- Pour `SearxProvider` : requête `HEAD` sur `$searxngUrl/healthz` ou une recherche minimale
- Pour `RsFeedProvider` : vérifier qu'au moins une URL de flux retourne HTTP 200
- Ajouter un flag `--skip-health-check` pour contourner en mode test ou hors ligne

---

### #15 — Tests unitaires : `SearxProvider::isClearlyIrrelevant()` `good first issue` `test`

🟢 **Difficulté :** Bonne première contribution

**Description :** `SearxProvider::isClearlyIrrelevant()` est le filtre principal du bruit sur les résultats web. Elle n'a aucune couverture de test. Les faux positifs suppriment silencieusement des offres valides.

**Critères d'acceptation :**
- Classe de test PHPUnit dans `tests/Service/Provider/SearxProviderTest.php`
- Couvre : URLs de documentation, sites tutoriels, job boards qui passent, job boards filtrés, cas limites (titre vide, unicode)
- Tous les tests passent via `make test`

**Pistes techniques :**
- La méthode peut être testée en isolation — aucun appel HTTP nécessaire
- Couvrir au moins 10 cas : 5 doivent être filtrés, 5 doivent passer

---

### #16 — Tests unitaires : fallback heuristique de `AIClient` `test`

🟡 **Difficulté :** Intermédiaire

**Description :** Le fallback heuristique dans `AIClient` est le filet de sécurité quand LM Studio est indisponible. Il est entièrement non testé.

**Critères d'acceptation :**
- Classe de test PHPUnit dans `tests/Service/AI/AIClientTest.php`
- Teste le chemin fallback en isolation (aucun appel HTTP, LM Studio non requis)
- Couvre : détection de stack, détection de contrat, détection du remote
- Cas limites : description vide, description en anglais, entrée malformée

---

### #17 — Tests d'intégration : `JobProcessor` `test`

🟡 **Difficulté :** Intermédiaire

**Description :** `JobProcessor` orchestre tout le pipeline. Il n'a aucun test d'intégration. Une régression dans le filtrage ou la déduplication passerait inaperçue.

**Critères d'acceptation :**
- Test dans `tests/Service/Processor/JobProcessorTest.php`
- Utilise une base SQLite en mémoire (pas la base de prod)
- Couvre : détection de doublons, filtrage par mots-clés, seuil de score, chemin fallback IA
- Les fixtures utilisent des objets `JobDTO`, aucun appel HTTP réel

**Pistes techniques :**
- Utiliser `SYMFONY_DEPRECATIONS_HELPER=disabled` dans `phpunit.xml` pour un output plus propre
- Mocker `AIClient` et `NotificationService` pour isoler la logique du processor
- `KernelTestCase` de Symfony donne accès aux vraies liaisons du container

---

### #18 — GitHub Actions CI `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Il n'existe pas encore de CI. PHPStan et Pint ne tournent qu'au travers du hook pre-push, qui est ignoré lors de commits via des interfaces graphiques ou si le hook n'est pas installé.

**Critères d'acceptation :**
- `.github/workflows/ci.yml` se déclenche sur chaque push et PR vers `main`
- Étapes : `composer install`, `make stan`, `make pint`, `make test`
- Matrice PHP : 8.3
- La CI passe sur un checkout propre sans LM Studio ni SearXNG

**Pistes techniques :**
- Utiliser `actions/cache` pour `vendor/` (clé de cache : hash de `composer.lock`)
- PHPStan doit tourner au niveau défini dans `phpstan.neon`

---

## Phase 4 — Productivité développeur

**Objectif :** Rendre JOBSCAN utilisable au quotidien sans friction.

---

### #19 — Commande `app:jobs:purge` `good first issue` `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Les offres s'accumulent indéfiniment dans SQLite. Il n'existe aucun moyen de nettoyer les anciennes entrées sans SQL brut.

**Critères d'acceptation :**
- Nouvelle commande : `php bin/console app:jobs:purge --older-than=30d`
- `--older-than` accepte `Nj` (jours) ou `Ns` (semaines), défaut : `30d`
- Le flag `--dry-run` affiche le compte sans supprimer
- Le nombre de suppressions est affiché à la fin

**Pistes techniques :**
- Commande dans `src/Command/PurgeJobsCommand.php`
- Ajouter `deleteOlderThan(\DateTimeImmutable $before): int` dans `JobRepository`
- Utiliser le `QueryBuilder` Doctrine avec une clause `WHERE created_at < :before`

---

### #20 — Commande `app:jobs:stats` `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Il n'existe aucun moyen d'obtenir un résumé de l'activité du pipeline sans interroger SQLite directement.

**Critères d'acceptation :**
- Nouvelle commande : `php bin/console app:jobs:stats`
- Affiche : total des offres, offres cette semaine, top 5 des sources par volume, distribution des scores (tranches : 0-40, 40-60, 60-80, 80-100), score moyen
- Flag optionnel `--format=json` pour le scripting

**Pistes techniques :**
- Commande dans `src/Command/JobStatsCommand.php`
- Ajouter des méthodes d'agrégation dans `JobRepository` (group by source, tranches de score)
- Utiliser le helper `Table` de la Console Symfony pour l'affichage terminal

---

### #21 — Mode `--dry-run` sur le pipeline `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Il n'existe aucun moyen sûr de tester le pipeline sans écrire en base ni envoyer de notifications Telegram.

**Critères d'acceptation :**
- `php bin/console app:jobs:run --dry-run` exécute le pipeline complet
- En mode dry-run : aucune écriture en base, aucun message Telegram
- Toutes les offres qui auraient été sauvegardées/notifiées sont affichées sur stdout avec leur score

**Pistes techniques :**
- Ajouter l'option `--dry-run` à `RunPipelineCommand`
- Injecter un flag `bool $dryRun` dans `JobProcessor` ou le passer dans un objet de contexte
- Logger `[DRY RUN] À sauvegarder : {titre} ({score}/100)` pour chaque offre

---

### #22 — Flag `--provider` pour filtrer les sources `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Le pipeline exécute toujours tous les providers. Il est impossible de lancer uniquement RSS ou uniquement SearXNG pour déboguer.

**Critères d'acceptation :**
- `php bin/console app:jobs:run --provider=rss` n'exécute que `RsFeedProvider`
- `--provider=searx` n'exécute que `SearxProvider`
- Omettre le flag exécute tous les providers (comportement actuel)
- Un nom de provider invalide affiche une erreur avec la liste des providers disponibles

**Pistes techniques :**
- Résoudre la collection de providers depuis l'itérateur taggé dans `RunPipelineCommand`
- Filtrer par nom court de classe ou par une méthode `getName(): string` sur l'interface

---

### #23 — Résumé de fin de run `good first issue` `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Le pipeline tourne silencieusement. À la fin, aucun résumé n'indique ce qui s'est passé : combien d'offres récupérées, scorées, notifiées.

**Critères d'acceptation :**
- Après `app:jobs:run`, un tableau récapitulatif est affiché :
  ```
  Offres récupérées  : 87
  Nouvelles offres   : 34
  Analysées par IA   : 28
  Fallback heurist.  : 6
  Notifiées          : 5
  Durée totale       : 12.4s
  ```
- Tous les compteurs sont exacts
- Le résumé est toujours affiché, même si 0 offres ont été trouvées

**Pistes techniques :**
- Ajouter un objet valeur `PipelineStats` pour accumuler les compteurs
- Le faire passer dans `JobProcessor` et collecter les résultats
- Utiliser `$output->writeln()` avec un bloc table `<info>` de la Console Symfony

---

### #24 — Notifications Telegram enrichies `good first issue` `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Les messages Telegram actuels ne contiennent que le titre, le score et l'URL. Il faut cliquer sur le lien pour évaluer l'opportunité.

**Critères d'acceptation :**
- Le message Telegram inclut : titre, score, type de contrat, remote, stack détectée (top 3), budget si disponible, et URL
- Le message reste sous la limite Telegram de 4 096 caractères
- Le format est lisible sur mobile

**Pistes techniques :**
- Modification dans `TelegramNotifier` (méthode de formatage)
- Passer l'entité `Job` complète plutôt que des champs individuels
- Utiliser le Markdown Telegram : `*Titre*`, `Score : 82/100`, code inline pour les tags de stack

---

### #25 — Seuil de notification configurable `good first issue` `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Le seuil de déclenchement des alertes Telegram est codé en dur à 60/100. Le modifier nécessite de toucher au code source.

**Critères d'acceptation :**
- Le seuil est configurable via `jobscan.yaml` : `notification_min_score: 60`
- La valeur par défaut reste 60
- Mettre à 0 envoie une notification pour chaque offre (utile pour les tests)

**Pistes techniques :**
- Ajouter le paramètre `%app.notification_min_score%` dans `jobscan.yaml`
- L'injecter dans `NotificationService` via le constructeur
- Remplacer la constante codée en dur dans `NotificationService`

---

### #26 — Poids du scoring configurables `refactor`

🟡 **Difficulté :** Intermédiaire

**Description :** Les poids du scoring (`+30 Symfony`, `+20 PHP`, `-50 Stage`, etc.) sont codés en dur dans `ScoringService`. Adapter JOBSCAN à un autre profil technique nécessite de modifier du PHP.

**Critères d'acceptation :**
- Tous les poids vivent dans `config/packages/jobscan.yaml` sous une clé `scoring_weights`
- `ScoringService` lit les poids depuis des paramètres injectés, pas des constantes
- Aucun changement de comportement avec les poids par défaut

**Pistes techniques :**
- Définir une map `scoring_weights` dans `jobscan.yaml` avec les valeurs actuelles comme défauts
- Injecter en tant que `array $weights` via le constructeur avec `%app.scoring_weights%`
- Valider que toutes les clés attendues sont présentes au moment de la compilation du container

---

### #27 — Export CSV/JSON `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Il n'existe aucun moyen d'exporter les offres scorées pour les analyser dans un tableur ou un outil externe.

**Critères d'acceptation :**
- `php bin/console app:jobs:export --format=csv` sort sur stdout
- `--format=json` sort un tableau JSON
- `--min-score=60` filtre par seuil de score
- `--output=jobs.csv` écrit dans un fichier plutôt que sur stdout

**Pistes techniques :**
- Commande dans `src/Command/ExportJobsCommand.php`
- CSV : utiliser `fputcsv()` avec un stream `php://output`
- JSON : `json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)`

---

## Phase 5 — Scalabilité

**Objectif :** Supporter des déploiements multi-utilisateurs et des environnements plus robustes.

---

### #28 — Internationalisation du prompt IA (FR/EN configurable) `enhancement`

🟢 **Difficulté :** Bonne première contribution

**Description :** Le prompt système envoyé à LM Studio est en français. Certains modèles peuvent produire de meilleurs résultats avec un prompt en anglais.

**Critères d'acceptation :**
- `jobscan.yaml` accepte une clé `ai_prompt_language: fr` (`fr` ou `en`)
- Passer à `en` utilise une version anglaise du même prompt
- Les deux prompts produisent une sortie structurée équivalente

**Pistes techniques :**
- Stocker les prompts comme clés YAML séparées : `ai_system_prompt_fr` et `ai_system_prompt_en`
- Injecter le bon prompt dans `AIClient` selon la valeur de config

---

### #29 — Intégration Symfony Scheduler `enhancement`

🟡 **Difficulté :** Intermédiaire

**Description :** L'automatisation nécessite actuellement un cron système. Le Scheduler Symfony (disponible depuis Symfony 6.3) peut gérer cela nativement sans configuration cron externe.

**Critères d'acceptation :**
- `RunPipelineCommand` peut être déclenché via `symfony/scheduler`
- Planification par défaut : toutes les 30 minutes
- La planification est configurable sans modification de code
- L'exécution manuelle de `app:jobs:run` continue de fonctionner

**Pistes techniques :**
- Implémenter une classe `PipelineSchedule` utilisant `RecurringMessage`
- L'enregistrer dans `config/packages/scheduler.yaml`
- Nécessite la configuration d'un transport Symfony Messenger

---

### #30 — Migration optionnelle vers PostgreSQL / MySQL `enhancement`

🟡 **Difficulté :** Intermédiaire

**Description :** SQLite est la valeur par défaut et fonctionne bien en local. Les déploiements partagés nécessitent un serveur de base de données.

**Critères d'acceptation :**
- Passer à PostgreSQL ou MySQL ne nécessite que de changer `DATABASE_URL`
- Toutes les migrations Doctrine s'exécutent proprement sur PostgreSQL
- Aucune syntaxe spécifique SQLite ne subsiste dans les migrations ou les requêtes
- Le README documente le chemin de migration

**Pistes techniques :**
- Vérifier les migrations pour des constructions spécifiques SQLite (ex. : `AUTOINCREMENT` vs `SERIAL`)
- Tester avec `DATABASE_URL=postgresql://user:pass@localhost:5432/jobscan`

---

### #31 — Support multi-profils utilisateur `enhancement`

🔴 **Difficulté :** Avancé

**Description :** JOBSCAN est actuellement mono-profil. Une équipe de développeurs (PHP, Python, Go) ne peut pas partager une instance avec des configurations de scoring différentes.

**Critères d'acceptation :**
- Plusieurs profils peuvent être définis dans `jobscan.yaml`, chacun avec ses propres mots-clés, stack, requêtes et poids de scoring
- `app:jobs:run --profile=python` exécute le pipeline avec le profil `python`
- Les notifications incluent le nom du profil
- Les profils partagent la même base avec une colonne `profile`

**Pistes techniques :**
- Ajouter une map `profiles` dans `jobscan.yaml`
- Un service `ProfileResolver` sélectionne le profil actif et l'injecte dans chaque service
- Nécessite une nouvelle colonne `profile` sur la table `job` (migration requise)

---

### #32 — API REST `enhancement`

🔴 **Difficulté :** Avancé

**Description :** Les offres et scores ne sont accessibles que via SQLite ou la CLI. Une API permettrait des intégrations externes (dashboards, applications mobiles, webhooks).

**Critères d'acceptation :**
- `GET /api/jobs` retourne une liste paginée d'offres (avec score, source, contrat, remote)
- `GET /api/jobs/{id}` retourne une offre complète avec l'analyse IA
- `GET /api/stats` retourne les statistiques du pipeline
- L'API est en lecture seule, aucun endpoint d'écriture
- Les réponses sont en JSON avec une enveloppe cohérente

**Pistes techniques :**
- Utiliser `symfony/api-platform` ou des contrôleurs Symfony simples avec `JsonResponse`
- L'authentification est optionnelle pour une instance locale — documenter comment ajouter une clé API

---

### #33 — Stack Docker complète `enhancement`

🔴 **Difficulté :** Avancé

**Description :** Le `docker-compose.yml` actuel n'inclut pas de LLM local conteneurisé et nécessite des étapes de configuration manuelles.

**Critères d'acceptation :**
- `docker compose up` démarre un environnement entièrement fonctionnel (app + SearXNG + LLM local)
- Des healthchecks garantissent le démarrage ordonné des services dépendants
- `make up && make run-pipeline` fonctionne sur un clone propre sans configuration supplémentaire
- Le README documente l'alternative LM Studio utilisée (ex. : `ollama`)

**Pistes techniques :**
- Considérer `ollama/ollama` comme serveur LLM compatible Docker avec une API compatible OpenAI
- Définir `depends_on` avec `condition: service_healthy` pour le démarrage ordonné
- Documenter le passthrough GPU pour les performances

---

## Bonnes premières contributions

Liste rapide pour les nouveaux contributeurs. Tous les tickets ci-dessous ont un périmètre clair et ne nécessitent pas une connaissance approfondie de la base de code :

| # | Tâche | Label |
|---|-------|-------|
| [#1](#1--filtre-sur-lancienneté-des-offres-good-first-issue-enhancement) | Filtre sur l'ancienneté des offres | `good first issue` |
| [#3](#3--label-de-source-rss-par-domaine-good-first-issue-bug) | Label de source RSS par domaine | `good first issue` |
| [#4](#4--pagination-searxng-good-first-issue-enhancement) | Pagination SearXNG | `good first issue` |
| [#5](#5--rate-limiting-entre-les-requêtes-searxng-good-first-issue-bug) | Rate limiting entre les requêtes SearXNG | `good first issue` |
| [#11](#11--optimisation-des-insertions-en-batch-good-first-issue-enhancement) | Optimisation des insertions en batch | `good first issue` |
| [#12](#12--déclarations-phony-dans-le-makefile-good-first-issue-refactor) | Déclarations `.PHONY` dans le Makefile | `good first issue` |
| [#15](#15--tests-unitaires--searxprovidersisclearlyirrelevant-good-first-issue-test) | Tests unitaires : `SearxProvider::isClearlyIrrelevant()` | `good first issue` |
| [#18](#18--github-actions-ci-enhancement) | GitHub Actions CI | `good first issue` |
| [#19](#19--commande-appjobspurge-good-first-issue-enhancement) | Commande `app:jobs:purge` | `good first issue` |
| [#21](#21--mode---dry-run-sur-le-pipeline-enhancement) | Mode `--dry-run` | `good first issue` |
| [#23](#23--résumé-de-fin-de-run-good-first-issue-enhancement) | Résumé de fin de run | `good first issue` |
| [#24](#24--notifications-telegram-enrichies-good-first-issue-enhancement) | Notifications Telegram enrichies | `good first issue` |
| [#25](#25--seuil-de-notification-configurable-good-first-issue-enhancement) | Seuil de notification configurable | `good first issue` |
| [#27](#27--export-csvjson-enhancement) | Export CSV/JSON | `good first issue` |
| [#28](#28--internationalisation-du-prompt-ia-fren-configurable-enhancement) | Internationalisation du prompt IA | `good first issue` |

---

## Comment contribuer

### Installation

```bash
git clone https://github.com/mzeahmed/jobscan.git
cd jobscan
make setup          # installe les git hooks (PHPStan au pre-push)
composer install
cp .env .env.local  # renseigner TELEGRAM_BOT_TOKEN, AI_API_BASE, etc.
make migrate
```

### Choisir une tâche

1. Parcourir les sections ci-dessus — trouver un ticket 🟢 pour commencer
2. Vérifier les [issues ouvertes](https://github.com/mzeahmed/jobscan/issues) — la tâche est peut-être déjà en cours
3. Ouvrir une issue pour réserver une tâche avant de commencer à coder

### Avant d'ouvrir une PR

```bash
make stan    # analyse statique PHPStan
make pintf   # correction automatique du style (PSR-12)
make test    # suite de tests PHPUnit
```

Le hook pre-push lance PHPStan automatiquement. La CI tournera également sur votre PR.

### Nommage des branches

```
feat/add-dry-run-flag
fix/rss-source-label
test/searxprovider-filter
refactor/scoring-weights-yaml
```

### Format des commits

```
feat: ajouter le flag --dry-run à app:jobs:run
fix: utiliser le domaine du flux comme label source RSS
test: couvrir SearxProvider::isClearlyIrrelevant()
refactor: externaliser les poids de scoring vers le YAML
```

### Ajouter un nouveau provider

Implémenter `JobProviderInterface`, retourner `JobDTO[]`, taguer le service :

```php
// src/Service/Provider/MonProvider.php
final class MonProvider implements JobProviderInterface
{
    public function fetch(): array { /* retourner JobDTO[] */ }
    public function isHealthy(): bool { /* vérifier la connectivité */ }
}
```

```yaml
# config/services.yaml
App\Service\Provider\MonProvider:
    tags: ['app.job_provider']
```

---

## Labels GitHub

| Label | Utilisé pour |
|-------|-------------|
| `good first issue` | Tâches isolées et bien délimitées pour les nouveaux contributeurs |
| `enhancement` | Nouvelles fonctionnalités ou capacités |
| `bug` | Comportement incorrect ou problème de fiabilité |
| `refactor` | Restructuration interne sans changement de comportement |
| `test` | Ajout de couverture de tests |
| `documentation` | Améliorations du README, CONTRIBUTING, ROADMAP |
