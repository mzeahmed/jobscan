# Matching : compatibilité, fraîcheur et priorité

Une offre est appariée à **chaque profil de recherche actif** (voir [PROFILE.md](PROFILE.md)) à partir de ses
**exigences extraites** (voir [EXTRACTION.md](EXTRACTION.md)). Le calcul est **entièrement déterministe, en PHP** : le LLM
n'intervient qu'en amont, pour extraire les exigences, et ne note jamais une offre. Aucun appel au modèle n'est
nécessaire pour (re)calculer un matching.

```text
exigences de l'annonce ─┐
profil candidat ────────┼─► éligibilité (éliminatoire) ─► critères pondérés ─► MatchScore ─┐
profil de recherche ────┘                                                                  ├─► PriorityScore
date de publication ───────────────────────────────────► FreshnessScore ──────────────────┘
```

Le matching **ne remplace pas** le scoring historique (`Scoring`, score /100 de la table `job`) : les deux coexistent. Les **notifications Telegram** suivent le matching dès qu'un profil
est configuré (voir [NOTIFICATIONS.md](NOTIFICATIONS.md)) ; l'interface web continue d'utiliser l'ancien score.

## Principe central : inconnu ≠ incompatible

Une information qui manque — dans l'annonce *ou* dans le profil — rend le critère **inconnu** ou **sans objet** : il **sort du
calcul** au lieu de faire baisser le score. Seul un **écart constaté** entre l'annonce et le profil compte comme un échec.

| Statut d'un critère | Sens | Dans le score |
|---|---|---|
| `match` / `partial` / `mismatch` | évalué, satisfait, en partie, ou non | oui (entre 0 et 1) |
| `unknown` | l'information manque (annonce muette, profil incomplet) | non, mais réduit la **couverture** |
| `not_applicable` | rien à comparer (aucune préférence, aucune exigence) | non |

Exemples : une annonce sans salaire n'est pas moins bien notée qu'une annonce avec salaire insuffisant ; une annonce qui ne dit pas
si le poste est en télétravail n'est pas « sur site » ; un candidat qui n'a pas déclaré ses expériences n'a pas « zéro an d'expérience ».

## 1. Éligibilité (éliminatoire, indépendante du score)

Une offre qui déclenche l'un de ces critères est **éliminée**, quel que soit son score par ailleurs :

- un **mot-clé exclu** du profil de recherche figure dans le titre ou la description (mot entier : « stage » n'est pas « backstage ») ;
- un **intitulé exclu** figure dans le titre ;
- l'annonce **exige** une compétence exclue (par le profil de recherche ou par le candidat) ; une compétence seulement appréciée ou citée n'élimine pas ;
- le **contrat** annoncé est explicitement hors des contrats recherchés (profil de recherche, à défaut candidat) ;
- le **mode de travail** annoncé est explicitement hors des modes acceptés (profil de recherche, à défaut candidat).

Un contrat ou un mode de travail **inconnu** n'élimine jamais.

## 2. MatchScore (0–100)

Moyenne pondérée des critères **connus**, avec les poids du profil de recherche (`weights`, à défaut `app.profile.match_weights`) :

| Critère | Poids par défaut | Évaluation |
|---|---:|---|
| `required_skills` | 40 | compétences exigées couvertes par celles que le candidat **déclare** (couvertes / exigées) |
| `nice_skills` | 15 | idem pour les compétences appréciées **et** simplement citées |
| `title` | 15 | pertinence du poste : intitulé recherché dans le titre (100 %), titre qui en partage l'essentiel (80 %), mot-clé dans le titre (70 %), compétence ciblée citée (50 %), mot-clé dans la description (40 %) |
| `location` | 10 | lieu souhaité retrouvé dans l'annonce ; poste en télétravail complet = satisfait ; lieu absent = inconnu |
| `remote` | 5 | mode de travail préféré (100 %) ou accepté (80 %) |
| `contract` | 5 | contrat annoncé parmi les contrats recherchés |
| `salary` | 5 | rémunération annoncée face à la prétention (voir ci-dessous) |
| `seniority` | 3 | expérience cumulée du candidat face aux années demandées, ou au palier du niveau annoncé |
| `languages` | 1 | langues **exigées** parlées par le candidat |
| `availability` | 1 | sans objet : la date de démarrage n'est pas extraite des annonces |

Ce sont des **poids relatifs**, pas des points : un poids à 0 neutralise un critère, et le score est normalisé sur les critères qui s'appliquent.

- **Compétences** : la comparaison ignore la casse, les accents et la ponctuation, rapproche les **alias** (`app.profile.skill_aliases` : « Postgres » =
  « PostgreSQL ») et **ignore les numéros de version** (« PHP 8 » = « PHP »). Une compétence absente du profil est **manquante**, jamais supposée acquise.
- **Rémunération** : comparée à la prétention de même période (et de même contrat si l'annonce le précise), **sans conversion** de devise ni de période. Au moins
  égale à la prétention : 100 % ; fourchette qui la recoupe : 70 % ; en dessous : −30 points par tranche de 10 % d'écart (0 % à partir de 33 %).
  Devise différente : inconnu.
- **Séniorité** : années cumulées d'après les expériences déclarées (périodes qui se chevauchent comptées une fois). Une expérience supérieure à celle demandée n'est pas pénalisée.
  Paliers par défaut sans nombre d'années : junior 0, confirmé 2, senior 5 (`app.profile.seniority_years`).
- **Extraction heuristique** (LLM indisponible) : les compétences exigées sont **indéterminées** (inconnues) ; les technologies citées comptent comme appréciées.

## 3. Couverture et verdict

La **couverture** est la part du poids des critères applicables qui a pu être évaluée (0–100 %). Elle dit combien d'information soutient le score.

| Verdict | Condition |
|---|---|
| `eliminated` | un critère éliminatoire est déclenché |
| `insufficient_data` | couverture < `app.profile.match_min_coverage` (40 %) : à vérifier, pas écartée |
| `below_threshold` | MatchScore < `min_match_score` du profil de recherche |
| `eligible` | sinon |

## 4. FreshnessScore et PriorityScore

La fraîcheur suit le barème configurable de `FreshnessScorer` (< 6 h : 100 … > 14 j : 5). Une date **inconnue** vaut 25, une date peu fiable est plafonnée à 50,
jamais le maximum pour une date inconnue.

`PriorityScore = (75 × MatchScore + 25 × FreshnessScore) / 100` (`app.profile.priority_match_weight` / `priority_freshness_weight`), avec un **garde-fou** :

- une offre **éliminée** a une priorité de **0** ;
- une offre **sous le seuil** ou aux **données insuffisantes** ne dépasse **jamais** son MatchScore.

Une offre toute fraîche mais incompatible ne devient donc jamais prioritaire grâce à sa seule fraîcheur, et une offre compatible mais ancienne reste devant.

## 5. Résultats conservés, explicables, recalculables

Un résultat est conservé **par offre et par profil de recherche** (table `job_match`) : une même offre peut correspondre à plusieurs profils sans être dupliquée. Il contient
le verdict, les trois scores, la couverture, le détail de chaque critère avec son explication, les compétences correspondantes, manquantes (exigées / appréciées), les raisons
d'élimination, les informations inconnues, les **arguments à mettre en avant** et le **CV recommandé**.

- **Arguments** : tirés littéralement du profil déclaré (compétences avec leur niveau et leurs années, expériences et projets dont les compétences recoupent l'annonce, langues,
  critères satisfaits). Rien n'est ajouté ni inventé.
- **CV recommandé** : la version de CV associée au profil de recherche (`cv:`). Le choix d'après le *contenu* des CV viendra avec leur gestion.
- **Profil le plus pertinent** d'une offre : celui de priorité maximale (voir `app:jobs:priority`).

Le résultat est **dérivé et recalculable sans nouvel appel au LLM**. Il est marqué par une **empreinte** (candidat + profil de recherche + règles de calcul : alias, paliers,
couverture, `MatchScorer::RULES_VERSION`). Il est recalculé s'il est **périmé** : empreinte différente, ou offre réanalysée depuis. La fraîcheur évolue avec le temps et se rafraîchit à part.

## Commandes

```bash
php bin/console app:jobs:match               # calcule les résultats manquants ou périmés (profil, règles ou analyse modifiés)
php bin/console app:jobs:match --all         # recalcule tout
php bin/console app:jobs:match --priorities  # rafraîchit seulement fraîcheur et priorité (le temps passe)
php bin/console app:jobs:priority            # offres compatibles par priorité, chacune avec son meilleur profil
php bin/console app:jobs:priority --search-profile=backend --all --limit=50
php bin/console app:jobs:explain 123         # scores, critères, manques, éliminations, inconnus, arguments, CV
php bin/console app:jobs:explain 123 --recompute
```

Le matching est lancé **automatiquement** après chaque analyse (et ré-extraction) d'une offre ; un échec du matching (profil invalide, par exemple) n'empêche jamais l'analyse.
Sans profil candidat ni profil de recherche actif, rien n'est calculé et Jobscan fonctionne comme avant.

Quand relancer quoi :

| Vous avez modifié… | Lancez |
|---|---|
| le profil candidat, un profil de recherche, les alias, les paliers de séniorité | `app:jobs:match` (les résultats périmés sont détectés) |
| les poids de priorité ou le barème de fraîcheur | `app:jobs:match --priorities` |
| rien, simplement le temps qui passe | `app:jobs:match --priorities` (par exemple une fois par jour) |
| les offres anciennes, sans exigences extraites | `app:jobs:analyze --outdated`, puis `app:jobs:match` |

## Limites

- **Pas de date de démarrage** : le critère `availability` est sans objet tant que l'extraction ne la capture pas.
- **Alias et versions** : la liste d'alias est courte (à compléter dans `jobscan.yaml`) ; deux versions d'une même technologie sont confondues.
- **Pertinence du poste** : fondée sur la présence de mots, sans compréhension sémantique ; un mot-clé très générique (« api ») donne un signal faible (40 %).
- **Expérience** : une seule durée cumulée, pas par compétence ; les années déclarées par compétence ne sont pas comparées aux années demandées.
- **Qualité dépendante de l'extraction** : un petit modèle qui manque des compétences exigées produit un matching moins informatif ; l'extraction heuristique rend les compétences exigées inconnues.
- **Pas encore de recommandation de CV d'après leur contenu**, ni d'interface web fondée sur ces scores (lots suivants).
