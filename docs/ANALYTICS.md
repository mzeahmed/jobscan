# Analytics

Ce qui fonctionne dans votre recherche, calculé **localement sur vos données** (candidatures, historique de statuts, offres collectées). Rien n'est envoyé nulle part, aucune table n'est ajoutée.

```bash
php bin/console app:analytics:report                    # 90 derniers jours
php bin/console app:analytics:report --since=30d        # 30d, 12w ou all (tout l'historique)
php bin/console app:analytics:report --format=json      # pour un autre outil
```

Dans l'écran React : **Analytics** (`/analytics`, alimenté par `GET /api/v1/analytics`), avec le choix de la période (30 jours, 90 jours, 1 an, tout).

## Ce qui est mesuré

**Parcours des candidatures envoyées** (une candidature est « envoyée » quand sa date d'envoi est renseignée ; les brouillons ne comptent pas) :

| Mesure | Définition |
|---|---|
| Réponses | entretien, test technique, proposition ou refus (« sans réponse » et « retirée » n'en sont pas) |
| Entretiens, propositions | **atteints**, lus dans l'historique : une candidature refusée après un entretien l'a bien atteint |
| Acceptées, refusées, sans réponse | statut **actuel** |
| Délai médian de première réponse | entre l'envoi et la première réponse (médiane, pas moyenne) |

**Répartitions** des candidatures envoyées, avec réponses, entretiens et propositions : par **source** de l'offre, par **version de CV**, par **profil de recherche** (par son nom), par **canal** d'envoi. Une valeur non renseignée apparaît comme « non renseigné ».

**Rendement des sources**, sur les offres collectées (**hors doublons probables**, une offre vue par deux sources n'est comptée que pour la première) : collectées, compatibles (au moins un profil de recherche la juge compatible), notifiées, suivies, postulées.

## Lecture : des comptages, pas des preuves

- **Un pourcentage n'est affiché qu'à partir de 5 candidatures.** En dessous, seul le décompte apparaît (« 1/2 ») : « 1 réponse sur 2 » ne dit rien d'un CV ou d'un canal. Dans le JSON, le taux vaut alors `null`.
- **Les groupes ne sont pas comparables.** Un CV n'est pas « meilleur » parce qu'il a obtenu plus de réponses : il a pu servir pour d'autres offres, d'autres entreprises, d'autres périodes.
- Les chiffres reflètent ce que **vous** consignez : une candidature jamais passée à « postulé » n'existe pas ici.
- La fenêtre porte sur la date d'**envoi** (candidatures) ou de **première détection** (offres).

## Limites

- Pas de graphiques ni d'évolution dans le temps (tableaux seulement).
- Pas de test statistique : à ces effectifs, il n'aurait pas de sens.
- Le rendement des sources compte les offres « compatibles » selon les profils **actuels** ; modifier un profil puis relancer `app:jobs:match` change ces chiffres.
- Dépend de `make migrate` (tables `job_application*`, `notification_log`).
