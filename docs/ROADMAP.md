# JOBSCAN — Roadmap

> Les contributions sont les bienvenues. Consultez [CONTRIBUTING.md](CONTRIBUTING.md) pour démarrer ; le détail des évolutions est dans le [CHANGELOG](CHANGELOG.md).

## Vision

JOBSCAN est un copilote de recherche d'emploi auto-hébergé : il collecte des offres, les analyse (LLM local par défaut), les compare à votre profil, prévient sur Telegram et suit vos candidatures. Il n'envoie jamais rien à un recruteur.

**Contraintes de conception non négociables :**

- Aucune dépendance à une API externe payante
- Rien n'est inventé : une information inconnue reste inconnue, elle ne vaut pas incompatibilité
- La compatibilité est calculée en PHP, de façon déterministe
- Annonces et emails sont des données non fiables
- Les données personnelles et le CV ne partent pas vers un LLM distant sans autorisation
- Aucun envoi automatique à un recruteur

## Fait

- **Collecte** : RSS, RemoteOK, SearXNG, job boards ATS ([PROVIDERS](PROVIDERS.md)) ; persistance avant analyse, déduplication multi-sources, monitoring des providers
- **Analyse et matching** : extraction structurée validée ([EXTRACTION](EXTRACTION.md)), profil candidat et profils de recherche ([PROFILE](PROFILE.md)), scores de compatibilité, fraîcheur et priorité ([MATCHING](MATCHING.md))
- **Alertes** : notifications et boutons Telegram, rappels de relance ([NOTIFICATIONS](NOTIFICATIONS.md), [TELEGRAM](TELEGRAM.md), [REMINDERS](REMINDERS.md))
- **Candidatures** : suivi, gestion des CV, brouillons de message ([APPLICATIONS](APPLICATIONS.md), [CV](CV.md), [ASSISTANT](ASSISTANT.md))
- **Interface** : API `/api/v1` et écran React ([API](API.md), [UI](UI.md))
- **Exploitation** : déploiement Docker autonome, analytics, contrôle de santé et rapports ([DEPLOY](DEPLOY.md), [ANALYTICS](ANALYTICS.md), [MONITORING](MONITORING.md))

## Ouvert

- [ ] **Alertes email** : codées, à valider sur de vrais emails ([EMAIL](EMAIL.md))
- [ ] **France Travail** : code prêt et désactivé, accès à l'API restreint ([PROVIDERS](PROVIDERS.md))
- [ ] **Front** : passage à i18next 26 (avis de sécurité modéré sur la version 25)
- [ ] **Prompt IA** bilingue FR/EN
- [ ] **PostgreSQL / MySQL** : seul SQLite est pris en charge
- [ ] **Plusieurs utilisateurs** : aujourd'hui un compte unique
- [ ] **Pile Docker avec LLM conteneurisé**
- [ ] **Enrichissement optionnel** des offres par un service tiers, désactivé par défaut

## Écarté pour l'instant

- **Symfony Scheduler et Messenger** : cron suffit, voir [SCHEDULER](SCHEDULER.md)
