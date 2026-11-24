# Profil candidat et profils de recherche

Jobscan distingue deux choses, décrites dans deux fichiers YAML **locaux** :

- le **profil candidat** (`candidate.yaml`) : qui vous êtes, ce que vous maîtrisez, ce que vous cherchez ;
- les **profils de recherche** (`search_profiles.yaml`) : une ou plusieurs stratégies indépendantes
  (ex. « backend PHP/Symfony », « DevOps »), chacune avec ses mots-clés, ses compétences, ses
  exclusions, son seuil et ses pondérations.

> **État** : le profil est utilisé par le **matching** (voir [MATCHING.md](MATCHING.md)) pour calculer la compatibilité des offres analysées
> avec chaque profil de recherche actif. Le scoring historique (`Scoring`) est inchangé et reste celui des notifications. Aucune donnée du
> profil n'est envoyée à un LLM : le matching est entièrement local et déterministe.

## Démarrage

```bash
cd app
php bin/console app:profile:init       # crée candidate.yaml et search_profiles.yaml depuis les exemples
# éditez les fichiers de config/profile/local/ : remplacez TOUTES les valeurs d'exemple
php bin/console app:profile:validate   # contrôle et résume, sans jamais afficher le contenu
```

- `app:profile:init` ne remplace **jamais** un fichier existant, et crée les fichiers en `0600`.
- `app:profile:validate` signale en erreur toute incohérence (fichier nommé, champ fautif) et en
  avertissement les valeurs suspectes ; il prévient si un fichier est encore identique à l'exemple.
- Les exemples versionnés sont `app/config/profile/candidate.example.yaml` et
  `search_profiles.example.yaml`. Leurs valeurs sont **fictives**, compétences comprises : ce sont des
  exemples, pas des compétences que vous auriez.

## Confidentialité

Le profil contient des données personnelles (identité, expériences, prétentions salariales, chemins de CV).

- Les fichiers vivent dans `app/config/profile/local/`, **ignoré par Git** (`.gitignore` du dossier) et
  **exclu des images Docker** (`.dockerignore`). Ne les versionnez ni ne les partagez.
- Le dossier se change avec `JOBSCAN_PROFILE_DIR` (par exemple pour un volume Docker).
- Le profil n'est lu que localement. Aucune donnée du profil n'est envoyée à un LLM ; avant d'en
  transmettre (CV, préparation de candidature), une autorisation explicite sera exigée, désactivée par
  défaut, pour tout fournisseur distant.
- Les messages d'erreur nomment le fichier et le champ, jamais le contenu du profil.

## Principe : rien n'est supposé

Jobscan n'invente ni ne complète rien. Une compétence est « maîtrisée » si et seulement si elle figure
dans `skills`. Le niveau (`level`) et les années (`years`) restent inconnus tant que vous ne les
renseignez pas, et les calculs devront traiter une donnée inconnue comme inconnue, pas comme négative.
Une compétence ciblée par un profil de recherche mais non déclarée vous vaut un avertissement.

## `candidate.yaml`

| Clé | Contenu |
|---|---|
| `identity` | `full_name`, `headline`, `email` (validé), `phone` |
| `target_titles` | intitulés de poste recherchés |
| `skills` | `- PHP` ou `{name, level, years}` ; `level` : `beginner` `intermediate` `advanced` `expert` |
| `excluded_skills` | compétences exclues ou non maîtrisées |
| `experiences` | `company`, `title`, `start` et `end` (`AAAA-MM`, `end` omis si en cours), `summary`, `achievements`, `skills` |
| `projects` | `name`, `summary`, `url`, `skills`, `achievements` |
| `achievements` | réalisations transverses (faits que vous pouvez soutenir) |
| `certifications` | `- Nom` ou `{name, issuer, year}` |
| `languages` | `- Anglais` ou `{name, level}` (niveau libre : `B2`, `natif`…) |
| `location` | `cities`, `mobility` (`local` `regional` `national` `international`), `max_distance_km` |
| `remote_modes` | modes acceptés : `onsite` `hybrid` `remote` |
| `contracts` | types recherchés : `freelance` `cdi` `cdd` `alternance` `stage` |
| `salary` | `contract`, `period` (`daily` pour un TJM, `annual`), `min`, `max`, `currency` (défaut `EUR`) |
| `availability` | `from` (`AAAA-MM-JJ`), `notice_days`, `comment` |
| `cvs` | versions de CV : `id`, `label`, `path` (le fichier n'est ni lu ni vérifié) |

Les clés inconnues sont **rejetées** : une faute de frappe (`skils:`) est une erreur, jamais ignorée.

Règles vérifiées : pas de compétence en double (casse ignorée), pas de compétence à la fois déclarée et
exclue, fin d'expérience après son début, prétention avec au moins une borne et `min ≤ max`, identifiants de
CV uniques, e-mail, dates et devises valides.

## `search_profiles.yaml`

Une table `search_profiles` indexée par un identifiant (minuscules, chiffres, `-`, `_`) :

| Clé | Contenu |
|---|---|
| `name`, `enabled` | libellé (défaut : l'identifiant), activation (défaut : `true`) |
| `keywords`, `titles` | mots-clés et intitulés recherchés |
| `primary_skills`, `secondary_skills` | compétences prioritaires et appréciées |
| `locations`, `contracts`, `remote_modes` | vide : ceux du candidat |
| `excluded_keywords`, `excluded_titles`, `excluded_skills` | critères éliminatoires |
| `min_match_score` | seuil minimal de compatibilité, 0–100 (défaut : `app.profile.default_min_match_score`, 50) |
| `weights` | poids relatifs des critères (voir ci-dessous) |
| `cv` | identifiant d'une version de CV de `candidate.yaml` |

Une même offre peut correspondre à plusieurs profils sans être dupliquée ; les résultats de matching sont conservés par profil.

Règles vérifiées : identifiants uniques, compétence jamais à la fois ciblée et exclue (par le profil **ou** par
le candidat), jamais à la fois prioritaire et secondaire, version de CV existante, poids et seuil dans les bornes
avec au moins un poids strictement positif.

### Pondérations

Critères : `required_skills`, `nice_skills`, `title`, `location`, `remote`, `contract`, `salary`, `seniority`,
`languages`, `availability`. Ce sont des **poids relatifs** (entiers ≥ 0), pas des points : le score sera normalisé
au calcul, et un poids à 0 neutralise le critère. Les valeurs par défaut sont dans `app.profile.match_weights`
(`config/packages/jobscan.yaml`) ; un profil n'a qu'à surcharger ce qu'il veut changer.

## Fichiers et classes

| Rôle | Emplacement |
|---|---|
| Objets immuables (profil, compétences, poids…) et enums | `src/Profile/Model/` |
| Schémas de validation (Symfony Config) | `src/Profile/Config/` |
| Chargement, validation croisée, registre paresseux | `src/Profile/ProfileLoader.php`, `ProfileRegistry.php` |
| Commandes | `app:profile:init`, `app:profile:validate` |

Le registre charge le profil à la première utilisation : un fichier invalide ne perturbe que ce qui s'en sert.

## Limites connues

- Aucune gestion d'alias de compétences (« Symfony » et « Symfony Framework » sont deux compétences distinctes) ;
  la comparaison ignore seulement la casse et les espaces superflus.
- Le fichier d'un CV n'est jamais lu (seule son existence est vérifiée, voir [CV.md](CV.md)) ; la recommandation de version repose sur le profil de recherche, pas sur le contenu du CV.
