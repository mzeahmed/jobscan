# Rappels de relance

Jobscan vous rappelle, par Telegram, les relances **que vous avez planifiées**. Il ne relance personne à votre place et ne déduit aucune date : sans date de relance saisie, aucun rappel ne part.

## Planifier une relance

La date se saisit dans la fiche de la candidature (interface web, champ « Relance prévue »), ou en ligne de commande :

```bash
php bin/console app:applications:status 3 applied --follow-up=2026-12-15
```

## Envoyer les rappels

```bash
php bin/console app:reminders:send             # envoie les rappels dus
php bin/console app:reminders:send --dry-run   # aperçu, sans envoi ni marquage
php bin/console app:reminders:send --limit=5   # (défaut : 10 par exécution)
```

Jobscan n'a pas de planificateur : lancez la commande vous-même, par exemple une fois par heure en cron :

```cron
0 * * * * cd /chemin/vers/jobscan/app && php bin/console app:reminders:send >> var/reminders.log 2>&1
```

## Quand un rappel part

Une relance est **due** quand sa date est atteinte, que la candidature a été **envoyée** (pas un brouillon) et qu'elle est **encore ouverte** (ni acceptée, ni refusée, ni retirée). Elle déclenche **un seul rappel** par date :

- le rappel n'est enregistré que si Telegram a bien reçu le message ; sinon il est retenté au lancement suivant (la commande sort alors en échec) ;
- si vous changez la date (report, nouvelle date), le rappel est de nouveau armé ; réenregistrer le formulaire sans changer la date ne renvoie rien ;
- les plus en retard passent en premier, dans la limite par exécution ; le reste part au lancement suivant ;
- les notifications sont sonores (contrairement aux récapitulatifs).

Le message indique le poste, l'entreprise, le statut, la date d'envoi, le retard éventuel et le dernier contact. Il ne contient **ni nom ni contact de recruteur** : ce sont des données d'un tiers, qui n'ont pas à transiter par Telegram.

## Boutons du rappel

Avec `app:telegram:poll` en marche (voir [TELEGRAM.md](TELEGRAM.md)) :

| Bouton | Effet |
|---|---|
| ✅ Relancé | Consigne le contact (maintenant) et solde la relance. Planifiez la suivante si besoin. |
| ⏰ +3 j / ⏰ +7 j | Reporte la relance de 3 ou 7 jours à partir de maintenant ; un nouveau rappel partira à cette date. |

## Visibilité

Dans l'interface web, la liste des candidatures affiche le nombre de relances à faire et marque ⏰ celles dont la date est atteinte ; `app:applications:list` fait de même. Une relance due reste signalée tant qu'elle n'est pas faite, même après le rappel.

## Limites

- Pas de relance automatique suggérée après N jours sans réponse : seules les dates que vous saisissez comptent.
- Aucun envoi d'email au recruteur : Jobscan ne contacte jamais personne.
- Les rappels ne partent que si `app:reminders:send` est lancé.
- Un seul rappel par date : si vous ne réagissez pas, il n'est pas répété (la relance reste affichée comme due dans l'interface).
