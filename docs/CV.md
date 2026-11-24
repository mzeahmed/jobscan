# Gestion des CV

Les CV sont des **références locales** déclarées dans `candidate.yaml` (section `cvs` : `id`, `label`, `path`). Jobscan ne **lit jamais le contenu** d'un fichier de CV, ne le copie pas et ne le transmet à
aucun service (LLM compris). Il vérifie seulement qu'il existe, est lisible et non vide (taille et date de modification). Les fichiers et le profil restent hors du dépôt Git.

## Commandes

```bash
php bin/console app:cv:list                          # versions déclarées, état du fichier, profils qui les utilisent, envoyées / réponses
php bin/console app:cv:recommend 42                  # CV conseillé pour l'offre 42
php bin/console app:applications:cv 3 devops         # associe (ou change) la version de CV d'une candidature
php bin/console app:applications:cv 3 none           # retire l'association
```

## Recommandation

Le CV recommandé pour une offre est celui **associé (`cv:`) au profil de recherche qui la juge la plus pertinente** (priorité la plus haute parmi les profils compatibles). Rien n'est deviné :

- aucun profil ne juge l'offre compatible, ou le matching n'a pas été lancé → pas de recommandation ;
- le profil n'a pas de `cv:`, ou la version n'est pas déclarée → pas de recommandation, avec la raison ;
- le fichier du CV recommandé est introuvable ou vide → la recommandation est donnée avec un avertissement.

`app:applications:track` **suggère** le CV recommandé quand vous n'en précisez pas, mais ne l'applique jamais sans que vous le demandiez (`--cv` ou `app:applications:cv`).

## Statistiques par CV

`app:cv:list` affiche, par version, le nombre de candidatures **envoyées** (les brouillons ne comptent pas) et celles ayant reçu une **réponse** (entretien, test technique, proposition ou refus ;
« sans réponse » n'en est pas une). Ce sont des comptages bruts sur de petits échantillons : ils n'établissent pas qu'un CV est meilleur qu'un autre, car les offres visées diffèrent.

## Limites

- Pas de recommandation fondée sur le **contenu** du CV (il n'est pas lu) ni sur les compétences de l'offre : uniquement sur le profil de recherche.
- Pas de version, d'empreinte ni de sauvegarde des fichiers : si vous remplacez le PDF, la candidature garde seulement l'identifiant et le libellé de la version.
- Pas d'envoi : Jobscan ne joint aucun CV à un message (voir « Application Assistant »).
