# Suivi des candidatures

Jobscan consigne **ce que vous déclarez** : il n'envoie rien à un recruteur, ne remplit aucun formulaire, ne déduit ni n'invente aucune information. Les données saisies
(contact du recruteur, message envoyé, notes) sont personnelles : elles restent dans votre base locale, ne sont jamais journalisées ni transmises à un LLM ou à un tiers.

## Statuts et transitions

```text
Brouillon → Postulé → Entretien ⇄ Test technique → Proposition → Acceptée
                ↘ Sans réponse (réponse tardive possible)
Refusée / Retirée : possibles à tout moment après l'envoi (Retirée aussi depuis le brouillon)
```

Statuts : `draft`, `applied`, `interview`, `technical_test`, `offer`, `accepted`, `rejected`, `withdrawn`, `no_response`.
`accepted`, `rejected` et `withdrawn` sont **finaux**. Une transition interdite est refusée avec la liste des statuts possibles (un brouillon ne peut pas être « refusé » : rien n'a été envoyé).

Chaque changement est consigné dans l'**historique** (statut précédent, nouveau statut, date, note). La date est celle du fait réel (`--at`) : on peut consigner après coup une candidature
envoyée la semaine dernière. Elle ne peut ni être dans le futur, ni précéder le changement de statut précédent (hors sortie du brouillon).

Dates tenues à jour : `applied_at` (premier envoi), `responded_at` (première réponse : entretien, test, proposition ou refus), `last_contact_at` (dernier échange réel). « Sans réponse »
n'est ni une réponse ni un contact.

## Commandes

```bash
php bin/console app:applications:track 42 --cv=backend-php --search-profile=backend --channel=email --recruiter="Camille Martin" --contact="camille@exemple.fr"
php bin/console app:applications:status 1 applied --at=2026-12-01 --message="Bonjour, …" --note="Envoyée par email" --follow-up=2026-12-08
php bin/console app:applications:status 1 interview --at=2026-12-05
php bin/console app:applications:list [--status=applied]
php bin/console app:jobs:decide 42 saved        # saved | ignored | archived | none
```

- `42` est l'identifiant de l'offre (colonne `#` de `app:jobs:priority`). Une offre n'a qu'une candidature.
- `--cv` doit être une version de CV déclarée dans `candidate.yaml` ; le **libellé est copié** dans la candidature et reste lisible si le profil change. Une version inconnue est refusée, jamais devinée.
- `app:jobs:decide` : **sauvegarder**, **ignorer** ou **archiver** une offre sans candidature. Une offre ignorée ou archivée n'est plus notifiée ; `none` retire la décision.

## Protection contre la purge

`app:jobs:purge` ne supprime jamais une offre **sauvegardée, ignorée, archivée ou associée à une candidature**, quelle que soit son ancienneté : c'est votre historique. Le dry-run et la purge
indiquent combien d'offres anciennes ont été conservées pour cette raison. Seul `app:jobs:run --reset` (développement uniquement, interdit en production) efface tout, candidatures comprises.

Une offre ignorée est conservée volontairement : sans elle, une nouvelle collecte la ferait revenir comme nouvelle.

## Limites

- Pas encore d'interface web ni de commandes Telegram (lots suivants de la phase 3 et de la phase 4).
- La relance (`--follow-up`) est enregistrée mais aucun rappel n'est encore envoyé (« Rappels de relance », phase 4).
- Les versions de CV sont des références déclarées dans le profil : leur gestion détaillée est le lot suivant.
- Une table `application` orpheline (ancienne fonctionnalité, sans entité ni migration) peut exister dans une base de développement : Jobscan ne la touche pas, ses tables s'appellent `job_application*`.
