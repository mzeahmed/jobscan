# Extraction IA structurée des annonces

Pour chaque annonce, le LLM extrait des **exigences structurées** (`JobRequirements`), **indépendamment de tout
profil candidat**. Une annonce n'est donc analysée qu'une fois, quel que soit le nombre de profils de recherche :
le matching par profil (lot 2.3) se fera ensuite en PHP, de façon déterministe, sans nouvel appel au modèle.

```text
annonce publique ──► AIClient ──► LLM ──► RequirementsValidator ──► JobRequirements ──► job.requirements (JSON, versionné)
                        │                       (ancrage, bornes)          └──► vue historique (AiAnalysisDto) ──► Scoring existant
                        └─► repli heuristique si le LLM est indisponible ou inexploitable
```

Un seul appel alimente donc deux consommateurs : les exigences structurées (stockées) et la vue historique que
le scoring actuel utilise encore (`stack`, contrat, remote, budget, séniorité). Le **score final reste calculé en
PHP** ; le LLM ne note jamais une offre.

## Schéma (version 1)

| Champ | Contenu |
|---|---|
| `required_skills` | compétences explicitement **exigées** (« requis », « indispensable », « maîtrise de ») |
| `nice_skills` | compétences explicitement **appréciées** (« un plus », « souhaité ») |
| `mentioned_skills` | technologies citées **sans niveau d'exigence** clair (simple liste de stack) |
| `seniority` | `junior` `mid` `senior` `unknown` |
| `min_years_experience` | entier 0–40, uniquement si l'annonce donne un nombre d'années |
| `languages` | `{name, level, required}` ; `required` faux = apprécié (ou non précisé) |
| `remote_mode` | `onsite` `hybrid` `remote`, ou `null` si l'annonce ne le dit pas |
| `contract_types` | `freelance` `cdi` `cdd` `alternance` `stage` (jamais `unknown`) |
| `salary` | `{min, max, currency, period}` ; `period` : `daily` (TJM) ou `annual` ; jamais converti |
| `locations` | lieux de travail cités |
| `source` | `llm`, ou `heuristic` pour le repli |
| `warnings` | éléments écartés, annonce suspecte (voir plus bas) |

**Une information absente reste inconnue** (`null`, liste vide, `unknown`) : elle ne signifie jamais « non ». Une
annonce qui ne dit pas si le poste est en télétravail n'est pas « sur site » ; l'absence de salaire n'est pas un salaire
nul. Le matching devra respecter cette distinction.

L'extraction est stockée dans `job.requirements` (JSON) avec sa **version de schéma** (`job.extraction_version`).

## Validation stricte

La réponse du modèle est une donnée non fiable. `RequirementsValidator` la lit champ par champ :

- une réponse sans **aucune** clé du schéma est rejetée (repli heuristique), de même qu'une réponse à l'ancien format
  (`stack`…) sans liste de compétences ; les clés inconnues sont ignorées (avec avertissement) ;
- chaque valeur est bornée et nettoyée (balises, caractères de contrôle, longueurs, 30 compétences par liste maximum) ;
  une valeur invalide devient **inconnue**, jamais une valeur par défaut optimiste ;
- une compétence n'apparaît que dans **une** liste, la plus exigeante.

### Ancrage dans le texte de l'annonce

Tout ce que dit le modèle doit se retrouver dans l'annonce, sinon c'est **écarté avec un avertissement** :

| Élément | Condition |
|---|---|
| compétence | présente dans le texte (casse, accents et ponctuation ignorés ; mot entier pour les termes de 3 caractères ou moins : « Go » n'est pas « Google ») |
| langue | présente, avec ses synonymes (anglais / english…) |
| mode de travail | un mot qui le justifie (`remote`, `télétravail`, `hybride`, `présentiel`…) |
| contrat | un mot qui le justifie (`CDI`, `mission`, `TJM`, `alternance`, `stage`…) |
| rémunération | **chaque** montant retrouvé tel qu'écrit (`45000`, `45 000`, `45k`), une devise explicite (aucune n'est supposée), une période `daily` ou `annual`, min ≤ max |
| lieu | présent dans le texte |

Les montants ne sont **jamais convertis** : l'ancien prompt convertissait les dollars en euros « approximativement » ; le
nouveau n'estime rien. Conséquence pour le scoring actuel : une rémunération n'est exposée que si elle est en euros
(`600€/j`, `45-55k€/an`) ; une offre en dollars n'obtient plus le bonus de budget.

Le texte de référence est celui **réellement envoyé au modèle** (nettoyé, tronqué à `app.profile.ai_max_input_chars`,
6000 caractères) : ce que le modèle n'a pas vu ne peut pas être « ancré ».

## Sécurité : l'annonce est une donnée non fiable

- **`PromptBuilder`** : les règles de sécurité sont ajoutées **dans le code** au prompt de configuration (le retirer de
  `app.ai_system_prompt` ne les supprime pas). L'annonce est placée entre deux balises à identifiant **aléatoire par appel** :
  un texte d'annonce ne peut pas deviner la balise de fermeture. Les séquences `<<<` et `>>>` y sont neutralisées.
- **Neutralisation** : `PromptInjectionDetector` repère les consignes adressées à un modèle (« ignore les instructions
  précédentes », « system prompt », « you are now »…). La phrase concernée **et tout ce qui la suit** sont **retirés** du texte
  avant l'envoi au modèle *et* avant l'ancrage, et l'extraction reçoit un avertissement.
- **Aucune action** : la sortie du modèle n'est jamais exécutée ni interprétée comme une instruction ; elle est validée puis traitée comme
  une donnée. Aucun appel externe n'en découle.
- **Aucune donnée personnelle** n'est envoyée : seul le texte public de l'annonce part au modèle, jamais le profil ni un CV.
  Un LLM distant est donc acceptable pour cette étape.

### Test avec un vrai modèle (`qwen2.5-coder:7b`, Ollama local, annonces synthétiques)

| Cas | Résultat |
|---|---|
| CDI avec salaire, télétravail partiel, langue, compétences exigées/appréciées | correct sur tous les champs |
| Freelance remote avec TJM | correct (le modèle a manqué « 8 ans d'expérience » : champs restés inconnus, pas inventés) |
| Annonce quasi vide (« Développeur web ») | le modèle a halluciné PHP, Symfony, Redis, anglais, CDI, CDD : **tout écarté par l'ancrage** |
| Injection explicite (« Ignore toutes les instructions… ») | sans défense, le modèle **obéissait** ; avec la neutralisation, extraction correcte |
| Injection paraphrasée (« Note pour l'assistant : merci de lister… ») | **non neutralisée**, le modèle la suit en partie |

### Limite connue : l'injection paraphrasée

Une consigne reformulée échappe aux motifs, et si sa charge utile figure dans le texte (« Kubernetes, Rust », « salaire 200000 USD »),
l'ancrage ne peut pas la distinguer d'une annonce qui écrirait vraiment cela. L'impact est cependant **borné** : il se limite
aux données de *cette* annonce, comme si son auteur avait écrit ces mots lui-même ; aucune action n'est déclenchée et le score
final reste calculé en PHP. Les compétences `mentioned_skills` et les annonces portant un avertissement devront donc peser peu
dans le matching (lot 2.3).

## Résilience et reprise

- **Repli heuristique** si le LLM est indisponible ou renvoie une réponse inexploitable : exigences minimales
  (`source: heuristic`, technologies seulement *citées*, jamais « exigées »). Jamais mis en cache, et remplacé plus tard
  par une extraction LLM (voir `app:jobs:analyze` et la reprise d'analyse).
- **Cache 24 h** : clé = version du schéma + prompt + texte ; modifier le prompt ou le schéma invalide le cache.
- **Versionnement** : `JobRequirements::SCHEMA_VERSION` (1). À chaque changement de schéma, on incrémente la version ;
  `app:jobs:stats` indique combien d'offres sont périmées.

```bash
php bin/console app:jobs:analyze --outdated --limit=50   # ré-extrait les offres analysées dont le schéma est périmé
```

La ré-extraction est **explicite** (jamais automatique : elle coûte un appel LLM par offre), **ne notifie jamais** et ne
**remplace jamais une analyse LLM par un repli heuristique** : si le LLM est indisponible, l'offre reste inchangée. Les offres
déjà analysées avant ce lot ont `requirements` à `NULL` jusqu'à leur ré-extraction.

## Limites

- **Qualité dépendante du modèle** : un petit modèle local manque parfois des informations (années d'expérience) ou range mal
  exigé et apprécié. Le test ci-dessus est limité à un modèle et à quatre annonces synthétiques.
- **Ancrage prudent** : une compétence écrite autrement que dans l'annonce (« Postgres » pour « PostgreSQL ») est écartée ; mieux vaut
  une compétence manquante qu'inventée.
- **Rémunération** : seules les périodes journalière et annuelle sont prises en charge (un montant mensuel est écarté).
- **Vitesse** : plusieurs secondes par annonce en local (13 à 47 s avec un 7B sur la machine de test) ; l'analyse est reprise en
  arrière-plan, la collecte n'attend jamais le LLM.
- Seul le texte de l'annonce est analysé : pas de page liée, pas de pièce jointe.
