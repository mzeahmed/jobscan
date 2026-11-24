# Monitoring et rapports

## Contrôle de santé : `app:health:check`

```bash
php bin/console app:health:check                 # tableau
php bin/console app:health:check --format=json   # pour un autre outil
php bin/console app:health:check --strict        # code de sortie non nul dès un avertissement
php bin/console app:health:check --alert         # prévient par Telegram quand l'état change
```

Rapide, gratuit et sans effet de bord : **aucun appel réseau, aucun appel au LLM**. Code de sortie : `0` si tout va bien ou s'il n'y a que des avertissements (`--strict` : non nul aussi pour un avertissement), `1` en cas de problème critique.

| Contrôle | Avertissement | Critique |
|---|---|---|
| Base de données | | inaccessible |
| Migrations | | non appliquées, ou table absente (`make migrate`) |
| Chaque source d'offres (d'après `provider_run`) | pas d'exécution depuis 12 h, dernière collecte en échec ou partielle | pas d'exécution depuis 36 h, **3 échecs consécutifs** |
| Analyses | 50 en attente, ou la plus ancienne depuis 24 h | |
| LLM | 30 % d'analyses de repli (heuristique) sur 7 jours, sur au moins 10 analyses | |
| Notifications | offre prioritaire non notifiée depuis plus de 6 h (Telegram injoignable ?) | |
| Rappels de relance | rappel non envoyé plus de 6 h après la date (le cron tourne-t-il ?) | |
| Disque (volume de `var/`) | moins de 10 % libre | moins de 5 % libre |
| Profils | profil candidat absent, ou aucun profil de recherche actif | profil invalide |
| Interface web | *(information : protégée par mot de passe, ou fermée)* | |

Une source **désactivée** par configuration n'est jamais un problème. Un contrôle qui plante est signalé « critique » avec un message lisible (« table absente : lancez `make migrate` »), sans SQL brut, et n'empêche pas les autres. Les seuils sont dans `config/packages/jobscan.yaml` (`app.health.*`).

### Alertes Telegram (`--alert`)

Un message part **quand l'ensemble des problèmes change** : un problème apparaît, change de gravité ou disparaît (« tout est rétabli »), jamais en boucle pour un problème qui dure, ni parce qu'un compteur varie. Un état critique est notifié avec du son, un avertissement en silence. L'empreinte des problèmes déjà signalés est dans `var/health.state` ; elle n'est mise à jour que si Telegram a bien reçu le message, sinon l'alerte est retentée au contrôle suivant. Les textes venant d'erreurs sont échappés.

## Rapport : `app:report:weekly`

```bash
php bin/console app:report:weekly                    # Markdown sur la sortie standard (7 jours)
php bin/console app:report:weekly --days=30
php bin/console app:report:weekly --output=rapport.md
php bin/console app:report:weekly --telegram         # + résumé silencieux sur Telegram
```

Le rapport contient : la **collecte** (offres collectées hors doublons, compatibles, notifiées), les **candidatures** de la période (envoyées, réponses, entretiens ou tests, propositions) et les relances à faire, les **cinq meilleures offres à trier** (compatibles, ni décidées ni candidatées), et la **santé** (les contrôles qui ne sont pas « ok »). Les nombres de la période ne comptent que ce qui s'y est passé : une réponse reçue il y a trois semaines n'est pas dans un rapport de 7 jours.

Le résumé Telegram ne contient que des décomptes et des intitulés d'annonces publiques : **aucun nom, contact ni message de recruteur**. Il est borné sous la limite de Telegram et échappé.

Pour l'évolution de fond (réponses par source, CV, profil), voir [ANALYTICS.md](ANALYTICS.md).

## Planification

Dans la pile Docker autonome (service `cron`, voir [DEPLOY.md](DEPLOY.md)) : contrôle de santé toutes les heures avec alerte, rapport le dimanche à 18 h 30. Hors Docker, un cron équivalent :

```cron
10 * * * *  cd /chemin/vers/jobscan/app && php bin/console app:health:check --alert
30 18 * * 0 cd /chemin/vers/jobscan/app && php bin/console app:report:weekly --telegram
```

## Limites

- Le contrôle ne **teste pas** le LLM, Telegram ni les sources en vrai (ce serait coûteux ou intrusif) : il déduit leur état de ce qui s'est passé (échecs de collecte, analyses de repli, notifications ou rappels restés en attente).
- Les sources ne sont jugées que si elles ont **déjà tourné** (`provider_run`) : une source jamais exécutée n'apparaît pas.
- Si Telegram lui-même est en panne, l'alerte ne peut pas partir : le contrôle en ligne de commande reste la référence.
- Pas de tableau de bord ni d'historique des états : seulement l'état courant et la dernière alerte.
