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
- [ ] Tests d'intégration sur `JobProcessor` → issue [#17](#17-test-tests-dintégration-sur-jobprocessor)
- [x] CI GitHub Actions (`.github/workflows/ci.yml`)

---

## Phase 2 — Qualité du signal

**Objectif :** Réduire le bruit, améliorer la précision des offres remontées.

---

### #1 `feat: filter job offers older than a configurable threshold`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

`JobProcessor` traite toutes les offres récupérées sans tenir compte de leur date de publication. Les offres de plus de 30 jours n'ont aucune valeur et consomment inutilement du quota d'analyse IA.

**Objectif**

Ajouter un filtre dans `JobProcessor::process()` avant l'appel IA. Le seuil doit être configurable dans `config/packages/jobscan.yaml` via `max_job_age_days`.

**Pistes techniques**
- Parser la date depuis `JobDTO` avant de passer à `AIClient`
- Ajouter `max_job_age_days: 30` dans `config/packages/jobscan.yaml`
- Offres ignorées : `$this->logger->debug('Job ignoré (trop ancien)', ['title' => ..., 'age_days' => ...])`

**Critères d'acceptation**
- Les offres dont la date dépasse le seuil configuré sont ignorées dans `JobProcessor`
- Seuil par défaut : 30 jours ; le modifier ne nécessite qu'une édition YAML
- Les offres ignorées sont loguées au niveau `debug` avec leur ancienneté en jours
- Les offres sans date parseable ne sont pas supprimées silencieusement — elles passent avec un log `debug`

---

### #2 `feat: implement advanced cross-provider deduplication using title similarity`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `enhancement`, `medium`

**Contexte**

La déduplication actuelle vérifie uniquement l'unicité de l'URL. La même offre publiée sur LinkedIn et RemoteOK (URLs différentes, même titre et entreprise) est traitée deux fois : deux appels IA, deux entrées en base, deux notifications Telegram.

**Objectif**

Ajouter une vérification de similarité sur le titre normalisé + le nom d'entreprise avant la persistance.

**Pistes techniques**
- Ajouter `findSimilar(string $title, string $company): ?Job` dans `JobRepository`
- Normaliser avant comparaison : minuscules, suppression ponctuation et stop words (`le`, `la`, `de`, `developer`, etc.)
- Utiliser `similar_text()` ou `levenshtein()` — seuil configurable, défaut 80 %
- Ajouter `dedup_similarity_threshold: 80` dans `jobscan.yaml`

**Critères d'acceptation**
- Deux offres de providers différents avec un titre normalisé identique et la même entreprise sont dédupliquées
- Le seuil de similarité est configurable dans `jobscan.yaml`
- Aucun faux positif : deux offres avec des titres similaires mais des entreprises différentes sont toutes les deux conservées
- La déduplication se produit dans `JobProcessor` avant l'appel IA

---

### #3 `fix: use feed domain as RSS source label instead of generic "feed"`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `bug`

**Contexte**

`RsFeedProvider` définit `source = 'feed'` pour toutes les offres, quel que soit le flux d'origine. Il est impossible de distinguer `remoteok.com` de `reddit.com` en base ou dans les statistiques.

**Objectif**

Extraire le domaine de l'URL du flux et l'utiliser comme valeur de `source` dans `JobDTO`.

**Pistes techniques**
- `parse_url($feedUrl, PHP_URL_HOST)` puis suppression du `www.`
- Garder simple : `remoteok.com` → `remoteok`, `www.reddit.com` → `reddit`
- Fallback vers `'feed'` en cas d'URL malformée

**Critères d'acceptation**
- `JobDTO->source` reflète le domaine du flux d'origine (ex. : `remoteok`, `reddit`)
- Les URLs malformées ou absentes tombent en fallback sur `'feed'` sans exception
- Les offres existantes en base ne sont pas affectées (aucune migration nécessaire)
- La modification est confinée à `RsFeedProvider`

---

### #4 `feat: add pagination support to SearXNG provider`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

`SearxProvider` récupère uniquement la première page de résultats SearXNG (~10 résultats par requête). Avec 7 requêtes configurées, le pipeline est plafonné à environ 70 offres par run.

**Objectif**

Ajouter un paramètre `pageno` et une limite de pages configurable par requête.

**Pistes techniques**
- Boucle sur `pageno` de 1 à `$maxPages` dans `SearxProvider::fetchQuery()`
- Ajouter `searx_max_pages: 2` dans `config/packages/jobscan.yaml`
- Sortir de la boucle immédiatement si le tableau `results` de la réponse est vide

**Critères d'acceptation**
- Chaque requête SearXNG récupère jusqu'à `searx_max_pages` pages (défaut : 2)
- Mettre `searx_max_pages: 1` restaure le comportement actuel
- La boucle s'arrête tôt si une page retourne 0 résultats
- La nouvelle clé de config est documentée avec un commentaire dans `jobscan.yaml`

---

### #5 `fix: add configurable delay between SearXNG queries to prevent flooding`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `bug`

**Contexte**

7 requêtes sont envoyées en rafale à SearXNG à chaque run. Sur une instance locale, cela peut provoquer la mise en file d'attente des requêtes, des résultats incomplets ou un rate limiting silencieux.

**Objectif**

Insérer un délai inter-requête configurable (défaut 500 ms) dans `SearxProvider`.

**Pistes techniques**
- `usleep($delayMs * 1000)` entre chaque itération dans `SearxProvider`
- Ajouter `searx_query_delay_ms: 500` dans `config/packages/jobscan.yaml`
- `0` désactive le délai (utile pour les tests)

**Critères d'acceptation**
- Un délai est inséré entre chaque requête SearXNG
- Délai par défaut : 500 ms, configurable via `jobscan.yaml`
- Mettre `0` désactive le délai sans autre effet de bord
- Le délai ne s'applique pas à l'appel healthcheck (si l'issue #14 est implémentée)

---

### #6 `feat: make LM Studio model configurable via jobscan.yaml`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `enhancement`, `medium`

**Contexte**

`AIClient` lit le nom du modèle uniquement depuis la variable d'env `AI_MODEL`. Il n'est pas possible de le surcharger par profil ou de comparer deux modèles sans modifier `.env.local`.

**Objectif**

Faire de `jobscan.yaml` la source principale, avec `AI_MODEL` en fallback.

**Pistes techniques**
- Ajouter `ai_model: ~` (nullable) dans `config/packages/jobscan.yaml`
- Injecter via `%app.ai_model%` ; fallback sur `%env(AI_MODEL)%` si null
- Optionnel : ajouter `ai_model_fallback` pour quand le modèle principal est indisponible

**Critères d'acceptation**
- Définir `ai_model: mistral-7b` dans `jobscan.yaml` surcharge `AI_MODEL` sans toucher à `.env.local`
- Omettre `ai_model` (ou le mettre à `~`) revient à utiliser la variable d'env `AI_MODEL`
- Changer de modèle ne nécessite aucune modification PHP
- Le nom du modèle résolu est logué au niveau `debug` au démarrage du pipeline

---

### #7 `refactor: normalize AI output into a typed AiAnalysisResult value object`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `refactor`, `medium`

**Contexte**

`AIClient` retourne des valeurs texte libres pour `contract`, `remote` et `budget` (ex. : `"remote"`, `"full remote"`, `"yes"`, `"700€/j"`). `ScoringService` applique des correspondances de chaînes sur ces valeurs inconsistantes, ce qui casse silencieusement dès que le modèle utilise une formulation différente.

**Objectif**

Toute la normalisation doit être effectuée une seule fois dans `AIClient` et exposée via un objet `AiAnalysisResult` typé.

**Pistes techniques**
- Créer `src/DTO/AiAnalysisResult.php` avec des propriétés typées :
  - `contract: ContractType` (enum : `freelance`, `cdi`, `cdd`, `unknown`)
  - `remote: RemoteType` (enum : `full`, `partial`, `none`, `unknown`)
  - `budget: ?int` (TJM en euros, ou null)
  - `stack: string[]`
  - `seniority: ?string`
- Le fallback heuristique doit également retourner un `AiAnalysisResult` normalisé
- Mettre à jour `ScoringService` pour utiliser l'objet typé

**Critères d'acceptation**
- `AIClient::analyze()` retourne `AiAnalysisResult` dans tous les chemins d'exécution (LLM + fallback heuristique)
- `contract` est toujours l'une des valeurs : `freelance`, `cdi`, `cdd`, `unknown` — aucune chaîne brute ne fuite dans `ScoringService`
- `remote` est toujours : `full`, `partial`, `none`, `unknown`
- `budget` est `null` ou un entier positif (TJM)
- `ScoringService` n'effectue plus aucune correspondance de chaîne sur les sorties brutes du LLM
- Le comportement de scoring est inchangé avec les entrées par défaut

---

### #8 `feat: add seniority and budget scoring bonuses`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `enhancement`, `medium`

**Contexte**

`ScoringService` ignore la séniorité et le budget. Une offre junior full remote score identiquement à une offre senior. Une offre à 200 €/jour rank pareil qu'une à 700 €/jour.

**Dépend de :** issue #7 (objet `AiAnalysisResult` typé)

**Pistes techniques**
- Ajouter dans `jobscan.yaml` sous `scoring_weights` :
  ```yaml
  seniority_senior: 10
  seniority_lead: 15
  min_daily_rate: 500
  daily_rate_bonus: 10
  ```

**Critères d'acceptation**
- `ScoringService` applique un bonus pour la séniorité détectée (`senior` : +10, `lead` : +15 par défaut)
- `ScoringService` applique un bonus quand le TJM détecté dépasse le seuil configuré
- Tous les bonus sont configurables dans `jobscan.yaml` sans modification PHP
- Le score reste plafonné à 100
- Le comportement est inchangé quand la séniorité ou le budget sont `null` ou `unknown`

---

## Phase 3 — Robustesse & Tests

**Objectif :** Rendre le pipeline fiable et sûr à faire évoluer.

---

### #9 `fix: add exponential backoff retry on LM Studio HTTP errors`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `bug`, `medium`

**Contexte**

Une seule erreur réseau ou timeout fait tomber `AIClient` silencieusement dans le fallback heuristique, sans aucune tentative de relance. Un incident transitoire LM Studio (chargement du modèle, pause GC) dégrade définitivement l'analyse pour cette offre.

**Pistes techniques**
- Encapsuler l'appel HTTP dans une boucle de retry (max 3 tentatives)
- Délais : 1 s → 2 s → 4 s (exponentiel)
- Capturer `\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface`
- Après 3 échecs : déclencher le fallback heuristique et loguer en `warning`

**Critères d'acceptation**
- `AIClient` effectue jusqu'à 3 tentatives en cas d'erreur réseau ou timeout avant le fallback
- Les délais de retry suivent le backoff exponentiel : 1 s, 2 s, 4 s
- Après épuisement des tentatives, le fallback tourne et un `warning` est logué avec le titre de l'offre et l'erreur
- Le nombre maximum de tentatives est configurable (défaut : 3)
- Une réussite à la tentative 2 ou 3 logue un `notice`

---

### #10 `feat: implement a circuit breaker for LM Studio calls`

**Difficulté :** 🔴 Avancé
**Labels :** `enhancement`, `advanced`

**Contexte**

Si LM Studio est indisponible, chaque offre déclenche un timeout complet de 120 s avant le fallback. Pour un run de 50 offres, le pipeline est bloqué pendant des heures. Il n'existe aucun mécanisme pour détecter que le LLM est hors service et cesser de tenter.

**Pistes techniques**
- Créer `src/Service/AI/CircuitBreaker.php` avec les états : `closed` (normal), `open` (sauter l'IA), `half-open` (tester un appel)
- Transitions : `closed` → `open` après N échecs ; `open` → `half-open` après le TTL de refroidissement ; `half-open` → `closed` en cas de succès ou → `open` en cas d'échec
- Persister l'état dans `var/circuit_breaker.json` pour la persistance inter-runs
- Injecter `CircuitBreaker` dans `AIClient` comme dépendance constructeur

**Critères d'acceptation**
- Après 3 échecs IA consécutifs (configurable), le circuit s'ouvre
- En état ouvert : aucun appel HTTP tenté, toutes les offres vont directement au fallback heuristique
- Le circuit se réinitialise en `half-open` après un refroidissement configurable (défaut : 5 min)
- Les transitions d'état sont loguées en `warning`
- Un flag `--reset-circuit-breaker` sur `app:jobs:run` force le circuit en état `closed`

---

### #11 `perf: batch Doctrine flushes in JobRepository to reduce SQLite transactions`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

`JobRepository::save()` appelle `$entityManager->flush()` après chaque offre individuelle. Pour un run qui trouve 50 nouvelles offres, cela représente 50 transactions SQLite séparées.

**Pistes techniques**
- Ajouter un compteur dans la boucle de sauvegarde ; appeler `flush()` toutes les 20 itérations
- Appeler `flush()` une dernière fois après la boucle pour persister le dernier lot partiel
- Appeler `$entityManager->clear()` après chaque flush pour libérer la mémoire de l'identity map

**Critères d'acceptation**
- Les offres sont persistées par lots de 20 (configurable)
- Un flush final après la boucle garantit qu'aucune offre n'est perdue dans un lot partiel
- `$entityManager->clear()` est appelé après chaque flush intermédiaire
- La taille du lot est configurable (ex. : via injection constructeur ou constante)

---

### #12 `chore: add .PHONY declarations to all non-file Makefile targets`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `chore`

**Contexte**

Aucune target du Makefile (`up`, `logs`, `bash`, `migrate`, etc.) n'est déclarée `.PHONY`. Si un fichier portant le même nom qu'une target existe à la racine du projet, Make ignore silencieusement la target au lieu de l'exécuter.

**Pistes techniques**
- Ajouter en tête du `makefile` :
  ```makefile
  .PHONY: help build up down logs bash migrate run-pipeline alerts fix-perms stan pint pintf setup test
  ```

**Critères d'acceptation**
- Toutes les targets non-fichiers du `makefile` sont listées dans `.PHONY`
- `touch up && make up` exécute toujours la target `up` correctement
- Aucun comportement Makefile existant n'est modifié

---

### #13 `refactor: harden Dockerfile with non-root user and multi-stage build`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `refactor`, `medium`

**Contexte**

Le Dockerfile actuel tourne en root, embarque les dépendances de dev dans l'image finale, et n'a pas de `.dockerignore`. Cela gonfle la taille de l'image et élargit inutilement la surface d'attaque.

**Pistes techniques**
- Stage 1 (`builder`) : `FROM php:8.3-cli AS builder`, lancer `composer install --no-dev --optimize-autoloader`
- Stage 2 (`final`) : `FROM php:8.3-cli`, `COPY --from=builder /app/vendor ./vendor` + code app uniquement
- `RUN useradd -u 1000 -m app && chown -R app:app /app` puis `USER app`
- Créer `.dockerignore` excluant : `.git/`, `tests/`, `var/`, `.env.local`, `node_modules/`

**Critères d'acceptation**
- Le processus container tourne sous un utilisateur non-root (`app`, UID 1000)
- L'image finale ne contient pas les dépendances Composer de dev
- `.dockerignore` existe et exclut `.git/`, `tests/`, `var/`, `.env.local`
- L'image finale est plus légère que l'image actuelle (vérifiable via `docker image ls`)
- `make up && make run-pipeline` continue de fonctionner après la modification

---

### #14 `feat: add isHealthy() to JobProviderInterface and check providers on startup`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `enhancement`, `medium`

**Contexte**

Il n'existe aucun moyen de vérifier que SearXNG ou les flux RSS sont accessibles avant le démarrage du pipeline. Une `SEARXNG_URL` mal configurée échoue silencieusement en cours de run après avoir gaspillé du temps sur les offres précédentes.

**Pistes techniques**
- `SearxProvider::isHealthy()` : envoyer une requête HTTP minimale ; retourner `false` en cas d'exception ou de réponse non-200
- `RsFeedProvider::isHealthy()` : vérifier qu'au moins une URL de flux retourne HTTP 200
- `RunPipelineCommand` : itérer les providers, avertir en cas d'indisponibilité, les ignorer, continuer avec les providers sains
- Ajouter l'option `--skip-health-check` à `RunPipelineCommand` pour contourner entièrement (utile hors ligne / en test)

**Critères d'acceptation**
- `JobProviderInterface` déclare `isHealthy(): bool`
- `SearxProvider` et `RsFeedProvider` l'implémentent tous les deux
- `RunPipelineCommand` appelle `isHealthy()` sur tous les providers avant de démarrer
- Un provider indisponible est logué en `warning` et ignoré — le run n'est pas annulé
- `--skip-health-check` contourne tous les checks
- Les checks de santé ne déclenchent pas un fetch complet

---

### #15 `test: cover SearxProvider::isClearlyIrrelevant() with PHPUnit`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `test`

**Contexte**

`SearxProvider::isClearlyIrrelevant()` est le filtre principal du bruit sur les résultats de recherche web. Elle n'a aucune couverture de test. Un faux positif supprime silencieusement une offre valide ; un faux négatif laisse passer des résultats hors sujet jusqu'au LLM.

La méthode est pure (pas d'I/O, pas d'HTTP), testable en totale isolation.

**Critères d'acceptation**
- Classe de test dans `tests/Service/Provider/SearxProviderTest.php`
- Au moins 10 cas de test : 5 URLs/titres qui doivent être filtrés, 5 qui doivent passer
- Cas filtrés : URLs de documentation, sites tutoriels, contenu non-emploi
- Cas passants : job boards connus, plateformes freelance, pages d'offres pertinentes
- Cas limites : titre vide, URL vide, caractères unicode dans le titre
- Tous les tests passent via `make test` sans LM Studio ni SearXNG

---

### #16 `test: cover AIClient heuristic fallback in isolation`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `test`, `medium`

**Contexte**

Le fallback heuristique dans `AIClient` est le seul chemin d'analyse disponible quand LM Studio est indisponible. Il est entièrement non testé. Une régression dans la détection de stack, de contrat ou de remote passerait inaperçue.

**Critères d'acceptation**
- Classe de test dans `tests/Service/AI/AIClientTest.php`
- Les tests exercent uniquement le chemin fallback heuristique — aucun appel HTTP, LM Studio non requis
- Couvre : détection de stack (PHP, Symfony, WordPress), détection du type de contrat (freelance, CDI), détection du remote
- Cas limites : description vide, description en anglais, description sans mots-clés techniques, JSON malformé retourné par le LLM (pour vérifier que le fallback se déclenche correctement)
- Tous les tests passent via `make test`

---

### #17 `test: write integration tests for JobProcessor`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `test`, `medium`

**Contexte**

`JobProcessor` orchestre tout le pipeline : déduplication, filtrage par mots-clés, dispatch IA, scoring et persistance. Il n'a aucun test d'intégration. Une régression dans l'une de ces étapes est actuellement indétectable sans exécuter le pipeline manuellement en entier.

**Pistes techniques**
- Utiliser `KernelTestCase` pour l'accès au container avec une vraie base SQLite en mémoire
- Mocker `AIClient` et `NotificationService` pour isoler la logique du processor
- Fixtures : construire des objets `JobDTO` directement, aucun appel HTTP
- Ajouter `SYMFONY_DEPRECATIONS_HELPER=disabled` dans `phpunit.xml` pour un output CI plus propre

**Critères d'acceptation**
- Classe de test dans `tests/Service/Processor/JobProcessorTest.php`
- Couvre : détection de doublons (même URL ignorée), filtrage par mots-clés (offre hors sujet ignorée), seuil de score (offre faible non notifiée), chemin fallback IA (le processor fonctionne quand l'IA retourne null)
- Utilise une base SQLite en mémoire, pas la base de développement
- `AIClient` et `NotificationService` sont mockés
- Tous les tests passent via `make test`

---

## Phase 4 — Productivité développeur

**Objectif :** Rendre JOBSCAN utilisable au quotidien sans friction.

---

### #19 `feat: add app:jobs:purge command to delete old job offers`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

Les offres s'accumulent indéfiniment dans SQLite. Il n'existe aucun moyen de nettoyer les anciennes entrées sans SQL brut. Sur le long terme, la base grossit sans limite et les performances de requête se dégradent.

**Pistes techniques**
- Commande dans `src/Command/PurgeJobsCommand.php`
- Ajouter `deleteOlderThan(\DateTimeImmutable $before): int` dans `JobRepository` via QueryBuilder Doctrine
- Parser l'argument `--older-than` : `30d` → `new \DateTimeImmutable('-30 days')`, `4w` → `new \DateTimeImmutable('-4 weeks')`

**Critères d'acceptation**
- `php bin/console app:jobs:purge --older-than=30d` supprime les offres de plus de 30 jours
- `--older-than` accepte `Nj` (jours) et `Ns` (semaines) ; défaut : `30d`
- `--dry-run` affiche le nombre d'offres qui seraient supprimées sans supprimer quoi que ce soit
- Le nombre de suppressions est affiché à la fin
- La commande sort avec le code 0 en cas de succès, 1 en cas d'argument invalide

---

### #20 `feat: add app:jobs:stats command for pipeline activity summary`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

Il n'existe aucun moyen d'inspecter l'activité du pipeline sans exécuter du SQL brut contre `var/jobscan.db`. Une commande stats donne une visibilité immédiate sur le volume, la distribution des sources et la qualité des scores.

**Pistes techniques**
- Commande dans `src/Command/JobStatsCommand.php`
- Ajouter des méthodes d'agrégation dans `JobRepository` : `countBySource()`, `countByScoreRange()`, `getAverageScore()`
- Utiliser le helper `Table` de la Console Symfony pour l'affichage terminal
- Pour `--format=json` : `json_encode($data, JSON_PRETTY_PRINT)`

**Critères d'acceptation**
- `php bin/console app:jobs:stats` affiche : total des offres, offres ajoutées cette semaine, top 5 des sources par volume, distribution des scores (tranches 0–40, 40–60, 60–80, 80–100), score moyen
- `--format=json` sort les mêmes données en objet JSON (pour le scripting)
- La commande fonctionne sur une base vide sans erreur
- Toutes les agrégations sont calculées via des méthodes de `JobRepository`, pas de SQL brut dans la commande

---

### #21 `feat: add --dry-run option to app:jobs:run`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

Lancer le pipeline écrit toujours en base et envoie des notifications Telegram. Il n'existe aucun moyen sûr de tester un changement de configuration, un nouveau provider ou un prompt modifié sans effets de bord.

**Pistes techniques**
- Ajouter l'option `--dry-run` dans `RunPipelineCommand::configure()`
- Passer un flag `bool $dryRun` dans `JobProcessor`, ou l'encapsuler dans un objet `PipelineContext`
- En mode dry-run : sauter `JobRepository::save()` et `NotificationService::notify()`
- Afficher chaque offre qui aurait été sauvegardée : `[DRY RUN] {titre} — {score}/100 ({source})`

**Critères d'acceptation**
- `php bin/console app:jobs:run --dry-run` exécute le pipeline complet (récupération, filtrage, analyse IA, scoring)
- Aucune ligne n'est écrite en base en mode dry-run
- Aucun message Telegram n'est envoyé en mode dry-run
- Chaque offre qui aurait été sauvegardée est affichée sur stdout avec son score
- Le mode dry-run est clairement indiqué en début d'output

---

### #22 `feat: add --provider option to run a single provider`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

`app:jobs:run` exécute toujours tous les providers enregistrés. Déboguer un provider spécifique (ex. : tester un nouveau flux RSS ou une modification de requête SearXNG) nécessite de lancer tout le pipeline et d'inspecter les logs manuellement.

**Pistes techniques**
- Utiliser l'itérateur taggé (`app.job_provider`) déjà injecté dans `RunPipelineCommand`
- Filtrer par nom court de classe ou ajouter `getName(): string` à `JobProviderInterface`
- Nom de provider invalide : afficher une erreur + liste des providers disponibles, code de sortie 1

**Critères d'acceptation**
- `php bin/console app:jobs:run --provider=rss` exécute uniquement `RsFeedProvider`
- `php bin/console app:jobs:run --provider=searx` exécute uniquement `SearxProvider`
- Omettre `--provider` exécute tous les providers (comportement actuel inchangé)
- Un nom de provider invalide affiche une erreur listant les options disponibles et sort avec le code 1

---

### #23 `feat: display a summary table at the end of each pipeline run`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

`app:jobs:run` produit des lignes de log pendant le run mais aucun résumé final. À la fin d'un run, il n'existe aucun moyen immédiat de savoir combien d'offres ont été récupérées, combien étaient nouvelles, combien ont déclenché une notification, ni combien de temps le tout a pris.

**Pistes techniques**
- Créer `src/DTO/PipelineStats.php` avec les compteurs : `fetched`, `new`, `analyzedByAI`, `heuristicFallback`, `notified`, `durationMs`
- Faire passer l'objet stats dans `JobProcessor` et incrémenter les compteurs au fur et à mesure
- Utiliser l'output Symfony Console avec un bloc tableau formaté en fin de `RunPipelineCommand::execute()`

**Critères d'acceptation**
- Après `app:jobs:run`, le résumé suivant est toujours affiché (même avec 0 résultats) :
  ```
  Offres récupérées  : 87
  Nouvelles offres   : 34
  Analysées par IA   : 28
  Fallback heurist.  : 6
  Notifiées          : 5
  Durée totale       : 12.4s
  ```
- Tous les compteurs sont exacts
- La durée est mesurée du démarrage de la commande à la fin
- Le résumé est supprimé quand le flag `--quiet` est utilisé

---

### #24 `feat: enrich Telegram notification messages with job details`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

Les notifications Telegram actuelles ne contiennent que le titre, le score et l'URL. Évaluer une offre nécessite de cliquer sur le lien. Le type de contrat, le remote, la stack détectée et le budget sont déjà disponibles dans l'entité `Job`.

**Pistes techniques**
- Modifier `TelegramNotifier` pour accepter l'entité `Job` complète plutôt que des champs individuels
- Utiliser le mode MarkdownV2 ou HTML de Telegram : `<b>Titre</b>`, `Score : 82/100`
- Pour la stack : joindre les 3 premières technologies détectées avec des virgules
- Garder le message total sous 4 096 caractères (limite Telegram)

**Critères d'acceptation**
- Le message Telegram inclut : titre (gras), score, type de contrat, remote, top 3 stack, budget (si disponible), URL
- Le message reste sous 4 096 caractères dans tous les cas
- Les champs `null` ou `unknown` sont omis du message plutôt qu'affichés en `null`
- Les tests existants de `TelegramNotifier` (le cas échéant) sont mis à jour

---

### #25 `feat: make Telegram notification score threshold configurable`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

Le score minimum pour déclencher une notification Telegram est codé en dur à 60 dans `NotificationService`. Le modifier nécessite de toucher au code source PHP.

**Pistes techniques**
- Ajouter `notification_min_score: 60` dans `config/packages/jobscan.yaml`
- Injecter via `%app.notification_min_score%` dans le constructeur de `NotificationService`
- Mettre à `0` envoie une notification pour chaque offre traitée (utile pour déboguer)

**Critères d'acceptation**
- `notification_min_score` dans `jobscan.yaml` contrôle le seuil d'alerte
- Valeur par défaut : 60 ; aucun changement de comportement avec la config par défaut
- Mettre à `0` envoie une notification pour chaque offre quel que soit son score
- Mettre à `100` désactive effectivement les notifications
- Aucun seuil codé en dur ne subsiste dans `NotificationService`

---

### #26 `refactor: externalize ScoringService weights to jobscan.yaml`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `refactor`, `medium`

**Contexte**

Tous les poids de scoring (`+30 Symfony`, `+20 PHP`, `-50 Stage`, etc.) sont codés en dur comme constantes ou littéraux dans `ScoringService`. Adapter JOBSCAN à un autre profil technique (Python, Go, Java) nécessite de modifier du PHP. Cela viole la contrainte de conception fondamentale du projet.

**Pistes techniques**
- Définir dans `jobscan.yaml` :
  ```yaml
  scoring_weights:
    php_in_title: 20
    symfony_in_stack: 30
    wordpress_in_stack: 15
    freelance: 20
    cdi: 15
    remote: 10
    recent_offer: 20
    mission_mention: 10
    urgent: 15
    internship_penalty: -50
    apprenticeship_penalty: -50
  ```
- Injecter en tant que `array $weights` dans le constructeur de `ScoringService` via `%app.scoring_weights%`
- Valider la présence de toutes les clés requises à la compilation du container via un `CompilerPass` ou une vérification `array_key_exists` dans le constructeur

**Critères d'acceptation**
- Tous les poids de scoring sont définis dans `config/packages/jobscan.yaml` sous `scoring_weights`
- `ScoringService` lit les poids depuis des paramètres injectés — aucun littéral codé en dur ne subsiste
- Des clés manquantes lèvent une exception descriptive à la compilation du container, pas à l'exécution
- Les poids par défaut produisent des scores identiques à l'implémentation actuelle
- Modifier un poids ne nécessite qu'une édition YAML et un clear du cache container

---

### #27 `feat: add app:jobs:export command for CSV and JSON output`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

Il n'existe aucun moyen d'exporter les offres scorées pour les analyser dans des outils externes (tableurs, outils BI, scripts). Le seul accès est SQLite brut ou l'inspection CLI.

**Pistes techniques**
- Commande dans `src/Command/ExportJobsCommand.php`
- CSV : utiliser `fputcsv()` sur un stream `php://output` ; inclure une ligne d'en-tête
- JSON : `json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)`
- Pour `--output=file.csv` : ouvrir le fichier avec `fopen($path, 'w')` au lieu de stdout

**Critères d'acceptation**
- `php bin/console app:jobs:export --format=csv` écrit du CSV sur stdout
- `php bin/console app:jobs:export --format=json` écrit un tableau JSON sur stdout
- `--min-score=60` filtre les résultats aux offres avec un score ≥ 60
- `--output=chemin/vers/fichier` écrit dans un fichier plutôt que sur stdout
- Le CSV inclut une ligne d'en-tête : `id,title,score,source,contract,remote,url,created_at`
- Une valeur `--format` invalide affiche une erreur et sort avec le code 1

---

## Phase 5 — Scalabilité

**Objectif :** Supporter des déploiements multi-utilisateurs et des environnements plus robustes.

---

### #28 `feat: make AI system prompt language configurable (FR/EN)`

**Difficulté :** 🟢 Bonne première contribution
**Labels :** `good first issue`, `enhancement`

**Contexte**

Le prompt système envoyé à LM Studio est en français. Certains modèles (variantes anglaises de Mistral, Llama 3) peuvent produire une sortie structurée plus fiable avec un prompt en anglais. Il n'existe actuellement aucun moyen de basculer sans réécrire manuellement le prompt dans `jobscan.yaml`.

**Pistes techniques**
- Ajouter `ai_prompt_language: fr` dans `jobscan.yaml` (valeurs acceptées : `fr`, `en`)
- Stocker les deux prompts comme clés YAML séparées : `ai_system_prompt_fr` et `ai_system_prompt_en`
- `AIClient` sélectionne le prompt selon `%app.ai_prompt_language%`

**Critères d'acceptation**
- `ai_prompt_language: en` dans `jobscan.yaml` bascule le prompt système LLM en anglais
- `ai_prompt_language: fr` (défaut) préserve le comportement actuel
- Les deux prompts demandent le même format de sortie JSON structuré
- Une valeur de langue invalide lève une exception descriptive à la compilation du container

---

### #29 `feat: integrate Symfony Scheduler for automated pipeline runs`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `enhancement`, `medium`

**Contexte**

Automatiser le pipeline nécessite actuellement de configurer un cron système manuellement. Le Scheduler Symfony (disponible depuis Symfony 6.3) peut gérer les tâches récurrentes nativement, éliminant la dépendance au cron externe.

**Pistes techniques**
- Implémenter `src/Scheduler/PipelineSchedule.php` utilisant `RecurringMessage::every()`
- Enregistrer dans `config/packages/scheduler.yaml`
- Nécessite un transport Symfony Messenger (in-memory suffisant pour l'usage local)
- L'exécution manuelle via `php bin/console app:jobs:run` doit rester inchangée

**Critères d'acceptation**
- Le pipeline se lance automatiquement toutes les 30 minutes via Symfony Scheduler quand le worker tourne
- La planification par défaut (30 min) est configurable sans modification de code
- `php bin/console messenger:consume scheduler` démarre le runner planifié
- L'exécution manuelle via `app:jobs:run` n'est pas affectée
- Le README documente comment démarrer le worker scheduler

---

### #30 `feat: ensure migrations run cleanly on PostgreSQL and MySQL`

**Difficulté :** 🟡 Intermédiaire
**Labels :** `enhancement`, `medium`

**Contexte**

Toutes les migrations Doctrine ont été écrites et testées uniquement contre SQLite. Basculer `DATABASE_URL` vers PostgreSQL ou MySQL peut échouer à cause de constructions SQL spécifiques SQLite (`AUTOINCREMENT`, `INTEGER PRIMARY KEY`, etc.). Les déploiements partagés ou en équipe ont besoin d'un chemin vers un serveur de base de données.

**Pistes techniques**
- Auditer tous les fichiers dans `migrations/` à la recherche de syntaxe spécifique SQLite
- Remplacer `AUTOINCREMENT` par `SERIAL` (PostgreSQL) — Doctrine le gère automatiquement si on utilise l'abstraction DBAL
- Tester avec : `DATABASE_URL=postgresql://user:pass@localhost:5432/jobscan php bin/console doctrine:migrations:migrate`

**Critères d'acceptation**
- Toutes les migrations s'exécutent sans erreur sur PostgreSQL 15+
- Toutes les migrations s'exécutent sans erreur sur MySQL 8+
- Aucune construction SQL spécifique SQLite ne subsiste dans les fichiers de migration
- `DATABASE_URL` est le seul changement requis pour basculer de base de données
- Le README documente le chemin de migration de SQLite vers PostgreSQL

---

### #31 `feat: support multiple user profiles in jobscan.yaml`

**Difficulté :** 🔴 Avancé
**Labels :** `enhancement`, `advanced`

**Contexte**

JOBSCAN est mono-profil : un seul ensemble de mots-clés, une seule stack, une seule configuration de scoring. Une équipe avec des profils mixtes (développeurs PHP, Python, Go) ne peut pas partager une instance sans écraser la configuration des autres.

**Pistes techniques**
- Ajouter une map `profiles` dans `jobscan.yaml`, chaque profil contenant ses propres `filter_keywords`, `known_stack`, `searx_queries` et `scoring_weights`
- Créer `src/Service/ProfileResolver.php` qui lit `--profile` depuis l'option CLI et injecte la config résolue dans chaque service dépendant
- Ajouter une colonne `profile VARCHAR(50)` dans la table `job` via une nouvelle migration Doctrine
- Le profil par défaut (sans flag `--profile`) utilise les clés de config de premier niveau pour la rétrocompatibilité

**Critères d'acceptation**
- Plusieurs profils peuvent être définis dans `jobscan.yaml` sous une clé `profiles`
- `php bin/console app:jobs:run --profile=python` exécute le pipeline avec la config du profil `python`
- Chaque offre en base stocke le profil sous lequel elle a été sourcée
- Les notifications Telegram incluent le nom du profil
- Omettre `--profile` revient à la config de premier niveau par défaut (aucune rupture)
- `php bin/console app:jobs:stats --profile=python` filtre les stats par profil

---

### #32 `feat: expose job offers and scores via a read-only REST API`

**Difficulté :** 🔴 Avancé
**Labels :** `enhancement`, `advanced`

**Contexte**

Les données d'offres ne sont accessibles que via SQLite ou les commandes CLI. Une API REST en lecture seule permet des intégrations externes : dashboards web, applications mobiles, consommateurs webhook, workflows scriptés.

**Pistes techniques**
- Utiliser des contrôleurs Symfony simples avec `JsonResponse` — API Platform est optionnel
- Paginer `GET /api/jobs` via le `Paginator` de Doctrine avec `?page=1&limit=20`
- Pour une auth API key optionnelle : middleware lisant un header `X-Api-Key` vérifié contre une variable d'env
- Retourner une enveloppe JSON cohérente : `{ "data": [...], "meta": { "total": 124, "page": 1 } }`

**Critères d'acceptation**
- `GET /api/jobs` retourne une liste paginée (score, source, contrat, remote, url, created_at)
- `GET /api/jobs/{id}` retourne une offre complète avec les données d'analyse IA
- `GET /api/stats` retourne les statistiques agrégées (total, par source, distribution des scores)
- Tous les endpoints sont en lecture seule (aucun POST/PUT/DELETE)
- Les réponses JSON ont une structure d'enveloppe cohérente
- L'API fonctionne sans authentification en localhost ; le README documente comment ajouter une protection par clé API

---

### #33 `feat: add containerized LLM (ollama) to docker-compose for a zero-setup stack`

**Difficulté :** 🔴 Avancé
**Labels :** `enhancement`, `advanced`

**Contexte**

Le `docker-compose.yml` actuel inclut l'app et SearXNG, mais pas de LLM local. Un contributeur doit installer et configurer LM Studio manuellement sur la machine hôte. `docker compose up` ne produit pas un environnement entièrement fonctionnel.

**Pistes techniques**
- Ajouter le service `ollama/ollama` dans `docker-compose.yml` ; il expose une API compatible OpenAI sur le port 11434
- Mettre à jour la valeur par défaut de `AI_API_BASE` dans `docker-compose.yml` : `http://ollama:11434/v1`
- Ajouter un `healthcheck` sur le service `ollama` ; définir `depends_on: ollama: condition: service_healthy` sur `app`
- Documenter le passthrough GPU (`deploy.resources.reservations.devices`) pour les GPUs NVIDIA dans le README

**Critères d'acceptation**
- `docker compose up` démarre app + SearXNG + ollama sans aucune étape manuelle
- `make up && make run-pipeline` fonctionne sur un clone propre (après le pull du modèle)
- Le container `app` attend le healthcheck `ollama` avant de démarrer
- Le README documente : quel modèle puller (`ollama pull mistral`), le passthrough GPU pour les performances, comment revenir à LM Studio
- LM Studio reste le défaut documenté pour l'usage hors Docker

---

## Bonnes premières contributions

Liste rapide pour les nouveaux contributeurs. Tous les tickets ci-dessous ont un périmètre clair et ne nécessitent pas une connaissance approfondie de la base de code :

| # | Titre | Labels |
|---|-------|--------|
| [#1](#1-feat-filter-job-offers-older-than-a-configurable-threshold) | Filtre sur l'ancienneté des offres | `good first issue` |
| [#3](#3-fix-use-feed-domain-as-rss-source-label-instead-of-generic-feed) | Label de source RSS par domaine | `good first issue` |
| [#4](#4-feat-add-pagination-support-to-searxng-provider) | Pagination SearXNG | `good first issue` |
| [#5](#5-fix-add-configurable-delay-between-searxng-queries-to-prevent-flooding) | Rate limiting SearXNG | `good first issue` |
| [#11](#11-perf-batch-doctrine-flushes-in-jobrepository-to-reduce-sqlite-transactions) | Batch insert Doctrine | `good first issue` |
| [#12](#12-chore-add-phony-declarations-to-all-non-file-makefile-targets) | `.PHONY` dans le Makefile | `good first issue` |
| [#15](#15-test-cover-searxprovidersisclearlyirrelevant-with-phpunit) | Tests `SearxProvider::isClearlyIrrelevant()` | `good first issue` |
| [#19](#19-feat-add-appjobspurge-command-to-delete-old-job-offers) | Commande `app:jobs:purge` | `good first issue` |
| [#21](#21-feat-add---dry-run-option-to-appjobsrun) | Flag `--dry-run` | `good first issue` |
| [#23](#23-feat-display-a-summary-table-at-the-end-of-each-pipeline-run) | Résumé de fin de run | `good first issue` |
| [#24](#24-feat-enrich-telegram-notification-messages-with-job-details) | Notifications Telegram enrichies | `good first issue` |
| [#25](#25-feat-make-telegram-notification-score-threshold-configurable) | Seuil de notification configurable | `good first issue` |
| [#27](#27-feat-add-appjobsexport-command-for-csv-and-json-output) | Export CSV/JSON | `good first issue` |
| [#28](#28-feat-make-ai-system-prompt-language-configurable-fren) | Internationalisation du prompt IA | `good first issue` |

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
| `chore` | Tâches d'outillage, CI, configuration |
| `advanced` | Conception complexe, impact transversal |
