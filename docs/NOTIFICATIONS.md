# Notifications Telegram

Les notifications suivent le **matching** (voir [MATCHING.md](MATCHING.md)) dès qu'un profil candidat **et** au moins un profil de recherche actif sont
configurés. Sans profil, l'ancien mécanisme reste en vigueur, tel quel : une alerte quand le score historique dépasse `app.profile.notification_threshold`.

Configuration Telegram inchangée : `TELEGRAM_BOT_TOKEN` et `TELEGRAM_CHAT_ID` dans `app/.env.local`.

## Trois niveaux

Le niveau d'une offre dépend de son **score de priorité** (compatibilité 75 % + fraîcheur 25 %), pour son profil de recherche le plus pertinent :

| Niveau | Priorité | Traitement (par défaut) |
|---|---:|---|
| 🔥 Opportunité prioritaire | ≥ 80 | **notification immédiate**, sonore |
| ⭐ Offre intéressante | ≥ 60 | notification immédiate, **silencieuse** (configurable : `immediate`, `digest`, `off`) |
| 📌 Offre secondaire | ≥ 40 | **récapitulatif** périodique (configurable : `digest`, `off`) |

Seuils et modes sont dans `config/packages/jobscan.yaml` (`app.notifications.*`). Les seuils doivent décroître (prioritaire ≥ intéressante ≥ secondaire).

**Seules les offres compatibles sont notifiées** : une offre éliminée, sous le seuil de son profil, ou aux données insuffisantes ne l'est jamais, **même très fraîche** (la fraîcheur
seule ne rend pas une offre prioritaire). Une offre dont l'extraction est **heuristique** (LLM indisponible, compétences exigées indéterminées) ne peut être qu'« intéressante », jamais « prioritaire ».
Une offre n'est notifiée que si son analyse est définitive et qu'elle n'est pas un doublon probable.

## Contenu d'une notification

Chaque champ n'apparaît que s'il est **disponible** : un salaire, un contrat ou une date inconnus sont omis, jamais remplacés par une valeur par défaut.

```text
🔥 Opportunité prioritaire
Développeur back-end PHP
Exemple SAS · Marseille
Contrat : cdi · Télétravail : complet · Salaire : 50 000–60 000 EUR/an
Publiée le 01/12/2026 · il y a 3 h

Compatibilité 92/100 · Fraîcheur 100/100 · Priorité 94/100
Profil : Backend PHP / Symfony · CV : CV Backend PHP

✓ 2/2 compétences exigées couvertes.
✓ L'intitulé recherché « Développeur back-end » figure dans le titre.
✓ Mode de travail préféré : remote.
✗ Manque : Kubernetes
? 1 information(s) non précisée(s) par l'annonce.

Voir l'offre
```

- **Publication et détection sont distinguées** : « Publiée le … · il y a … » quand la date de publication est connue (avec « (date peu fiable) » si elle l'est peu) ; sinon
  « Publication inconnue · détectée le … ». Une offre détectée aujourd'hui n'est pas présentée comme publiée aujourd'hui.
- **Raisons** : les trois critères satisfaits qui pèsent le plus, les compétences exigées manquantes (trois au plus), et le nombre d'informations que l'annonce ne donne pas. Un échec ou une
  information inconnue n'est jamais présenté comme une raison du matching.
- **Profil et CV** : le profil de recherche le plus pertinent et la version de CV qui lui est associée.
- Une analyse heuristique est signalée dans le message.
- Tout texte venant d'une annonce est **échappé** ; le message ne dépasse jamais la limite de Telegram (4096 caractères) ; le lien n'est cliquable que pour une URL `http(s)`.

## Récapitulatif périodique

`app:notifications:digest` envoie **un seul message** qui reprend les offres au mode « récapitulatif », les plus prioritaires d'abord, groupées par niveau. Jobscan n'a pas
(encore) de planificateur : **c'est à vous de lancer la commande** — par exemple une fois par jour par cron :

```cron
0 18 * * * cd /chemin/vers/jobscan/app && php bin/console app:notifications:digest >> var/digest.log 2>&1
```

```bash
php bin/console app:notifications:digest --dry-run        # aperçu, sans envoi ni marquage
php bin/console app:notifications:digest --max-items=10   # (défaut : app.notifications.digest_max_items = 15)
php bin/console app:notifications:digest --no-catchup
```

- Le message est borné : seules les offres qui y tiennent sont reprises **et marquées**. Les autres figurent dans le récapitulatif suivant, et le message annonce combien il en reste.
- Si l'envoi échoue, **rien n'est marqué** : les offres seront retentées au prochain lancement (code de sortie en échec).
- Le récapitulatif est silencieux et sans aperçu de liens.
- **Rattrapage** : la même commande envoie aussi les notifications *immédiates* manquées (Telegram indisponible lors de l'analyse), dans la limite de 10 par exécution.

## Pas de doublon

Une offre est notifiée **au plus une fois** — immédiatement ou dans un récapitulatif —, tracée dans `notification_log`. Elle n'est pas renotifiée si son score évolue, si elle est recalculée
ou ré-analysée, ni si elle l'a déjà été par l'ancien mécanisme (`notified_at`). Conséquence : une offre déjà reprise dans un récapitulatif comme « secondaire » ne déclenchera pas de notification
immédiate si elle devient « prioritaire » par la suite.

Les offres détectées depuis plus de `app.notifications.max_age_days` (3 jours) ne sont plus notifiées par le récapitulatif ni le rattrapage : après un changement de profil, des
dizaines d'offres anciennes devenues compatibles n'inondent pas votre Telegram.

## Corrections liées

- Les apostrophes des titres (« Ingénieur d'affaires ») étaient échappées en `&apos;`, que Telegram ne reconnaît pas ; elles le sont désormais en `&#039;`, y compris dans l'ancien message.

## Limites

- Le récapitulatif n'est envoyé que si la commande est lancée (pas de planification intégrée avant le lot de planification).
- Boutons et commandes : voir [TELEGRAM.md](TELEGRAM.md) (nécessite `app:telegram:poll`).
- Un seul canal : le chat configuré. Pas de notification par profil de recherche ni par appareil.
- Les notifications immédiates ne se déclenchent qu'à l'analyse (ou au rattrapage) : modifier un profil et recalculer le matching ne notifie pas les offres existantes, sauf via la commande de récapitulatif, dans la limite d'ancienneté.
