# Telegram interactif

Les notifications ([NOTIFICATIONS.md](NOTIFICATIONS.md)) portent des **boutons**, et le bot comprend quelques **commandes**. Rien n'est jamais envoyé à un recruteur : Jobscan consigne ce que vous faites.

## Boutons d'une notification

| Bouton | Effet |
|---|---|
| 📝 Suivre | Ouvre une candidature en **brouillon** (une seule par offre, même si vous appuyez deux fois). Le clavier propose alors ✅ *J'ai postulé*. |
| ✅ J'ai postulé | Consigne la candidature comme envoyée (maintenant), avec la note « Via Telegram ». |
| ⭐ Garder | Marque l'offre « sauvegardée » (protégée de la purge). |
| 🚫 Ignorer | Marque l'offre « ignorée » : plus notifiée ni proposée, mais conservée. |
| ↩️ Annuler | Retire la décision. |

Le clavier du message est mis à jour après chaque action. Les actions plus fines (dates, notes, CV, message envoyé) se font dans l'[interface web](UI.md) ou en ligne de commande.

## Commandes

- `/offres` : les 5 meilleures offres compatibles encore à trier (ni décidées, ni candidatées, ni doublons), chacune avec ses boutons ;
- `/candidatures` : décompte par statut et candidatures en cours ;
- `/aide` : rappel des commandes (tout autre texte donne aussi cette aide).

## Écoute : `app:telegram:poll`

```bash
php bin/console app:telegram:poll                  # écoute en continu (Ctrl+C pour arrêter)
php bin/console app:telegram:poll --once           # traite ce qui attend, puis s'arrête (cron)
php bin/console app:telegram:poll --max-seconds=3300
```

Jobscan **interroge** Telegram (`getUpdates`) : il n'ouvre **aucun port** et n'expose aucune adresse sur Internet, contrairement à un webhook. Conséquence : les boutons ne répondent que pendant que la commande tourne.
Pour qu'ils répondent toujours, lancez-la en service (systemd, conteneur) ou, au minimum, en cron toutes les minutes avec `--once` (la réponse peut alors prendre jusqu'à une minute).

La position de lecture est mémorisée dans `var/telegram.offset` : après un redémarrage, les mises à jour déjà traitées ne sont pas rejouées. Une mise à jour qui provoque une erreur est journalisée puis passée ; elle ne bloque pas l'écoute.

## Sécurité

- **Seul votre chat peut agir.** Pour un chat privé (`TELEGRAM_CHAT_ID` positif), l'expéditeur doit en être le titulaire. Toute autre mise à jour (autre chat, autre utilisateur) est **ignorée sans réponse** et journalisée sans son contenu.
- **Groupe ou canal** (identifiant négatif) : tout membre pourrait appuyer sur un bouton, donc les actions sont **désactivées** (la commande refuse de démarrer) tant que `TELEGRAM_ALLOWED_USER_IDS` ne liste pas les identifiants autorisés (séparés par des virgules).
- Les données des boutons sont validées strictement (`lettre` + nombre) ; une donnée mal formée, une offre inconnue ou un nombre démesuré ne changent rien.
- Le jeton du bot n'apparaît jamais dans les journaux : les messages d'erreur réseau (qui contiennent l'URL de l'API) sont masqués. Avant ce lot, un délai dépassé pouvait écrire l'URL, jeton compris, dans le journal.
- Les textes d'annonces affichés sont échappés ; les liens ne sont cliquables que pour `http(s)`.

## Limites

- Pas de webhook (choix délibéré : aucune exposition réseau).
- Les boutons des anciennes notifications restent actifs ; ils sont idempotents, mais agissent sur l'état courant de l'offre.
- Pas de saisie de texte libre par Telegram (message envoyé, notes, dates) ni de commande par identifiant d'offre.
- Un seul chat est géré.
