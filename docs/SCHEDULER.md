# Évaluation : Symfony Scheduler (et Messenger)

**Décision : ne pas adopter Scheduler ni Messenger pour l'instant.** La planification actuelle (service `cron` de la [pile Docker autonome](DEPLOY.md), supercronic) couvre le besoin ; Scheduler apporterait surtout un rattrapage des exécutions manquées dont l'application n'a pas besoin, au prix d'un processus permanent, de dépendances et d'un défaut réel (exécution en série). Voir « Quand réviser cette décision ».

## Méthode

Pas de lecture seule de documentation : un **prototype jetable** (projet Symfony 8.1 minimal, hors du dépôt) avec `symfony/scheduler` 8.1.10, `symfony/messenger` 8.1.11 et `dragonmantank/cron-expression` 3.6.1, un handler qui journalise ses départs et fins, et un worker `messenger:consume scheduler_default`. Chaque comportement ci-dessous a été **observé**, pas supposé.

## Ce qui a été observé

| Question | Observation |
|---|---|
| Précision | Écarts de **5,0 s** pour une tâche toutes les 5 s (6 exécutions sur 32 s). |
| Tâche plus lente que son intervalle (8 s toutes les 5 s) | **Jamais en parallèle**, **déclenchements manqués fusionnés** : 4 exécutions en 32 s, enchaînées sans trou. |
| Échec d'une tâche | Exécutée **une seule fois** (`Removing from transport after 0 retries`), pas de répétition d'effets de bord. L'erreur n'apparaît **que dans les journaux** ; rien n'est rejoué ni mis de côté. |
| Arrêt du worker puis redémarrage, planning ordinaire | Les exécutions manquées sont **perdues** (comme avec cron et supercronic). |
| Idem avec un planning **stateful** (`->stateful($cache)->processOnlyLastMissedRun(true)`) et un horaire `cron` | **Une seule** exécution de rattrapage dès le redémarrage (2 minutes manquées → 1 exécution à +0,1 s), sans doublon. *(Avec un intervalle relatif `every`, une exécution supplémentaire est apparue une seconde plus tard : à éviter pour un envoi Telegram.)* |
| Horaires de type cron | Exigent un **paquet de plus** (`dragonmantank/cron-expression`). |
| Démarrage et mémoire | Application Jobscan : **0,2 s** et **52 Mo** de pic pour démarrer une commande ; prototype minimal en worker : 45 Mo au repos. |
| Sécurité du prototype | `composer audit` : *No security vulnerability advisories found* avec les versions à jour (8.1.11). |

## Comparaison pour Jobscan

| | Cron (supercronic, actuel) | Scheduler + Messenger |
|---|---|---|
| Isolation | **un processus par tâche** : un plantage ou une fuite mémoire n'affecte que cette tâche | un worker permanent pour tout : mémoire à surveiller (`--memory-limit`, `--time-limit`, redémarrage) |
| Tâches concurrentes | en parallèle, chaque tâche sous `flock` | **en série** : une collecte de 20 minutes **retarde** les rappels de relance et le récapitulatif de 18 h |
| Exécutions manquées | perdues | rattrapées si « stateful » (mais l'application est déjà idempotente : le récapitulatif rattrape ses envois manqués, les rappels partent à la prochaine exécution, la collecte est incrémentale) |
| Où est défini le planning | un fichier `crontab` | du code PHP, testable |
| Échecs | code de sortie et journaux | journaux seulement (aucun transport d'échec configuré) |
| Dépendances | 1 binaire épinglé par empreinte | 4 paquets (`scheduler`, `messenger`, `clock`, `cron-expression`), des gestionnaires de messages à écrire pour chaque commande, une config de transport |
| Coût de démarrage par tâche | 0,2 s toutes les heures : négligeable | nul (mais 52 Mo résidents en permanence) |

Le point décisif est l'**exécution en série**. La collecte appelle des sources externes et un LLM : sa durée est imprévisible, et tout le reste attendrait derrière elle. Avec cron, rappels, contrôle de santé et récapitulatif restent à l'heure. Obtenir le même résultat avec Scheduler demanderait plusieurs plannings et plusieurs workers : on retrouve la complexité des processus séparés, sans leur simplicité.

## Messenger seul

Le seul bénéfice plausible serait d'analyser les offres en parallèle, hors du pipeline de collecte. Il est faible ici : la persistance avant analyse, les statuts (`pending`, `failed`, `fallback`) et la politique de reprise (`app:jobs:analyze`) existent déjà **dans la base**, et le goulot est le LLM local (`Ollama`), qui ne gagne rien à recevoir plusieurs requêtes à la fois. Messenger ajouterait une file, des workers et des modes de panne (messages perdus ou bloqués) pour un gain nul.

## Quand réviser cette décision

- Un besoin d'**analyse parallèle** réel (LLM distant à forte concurrence, plusieurs machines) : Messenger devient pertinent, et Scheduler presque gratuit par-dessus.
- Un besoin de **planning dynamique** (par utilisateur, modifiable depuis l'interface) : le crontab statique ne suffit plus.
- La disparition du service `cron` de la pile Docker pour réduire les composants (on échange alors un binaire contre quatre paquets).
- Si l'on adopte Scheduler : utiliser un horaire `cron` absolu plutôt que `every`, un planning *stateful*, `--time-limit` et `--memory-limit` sur le worker avec redémarrage automatique, **un worker par groupe de tâches** pour éviter l'exécution en série, et un transport d'échec.

## À quoi ressemblerait l'adoption (non appliquée)

```php
#[AsSchedule('default')]
final class JobscanSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $cache) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::cron('0 */3 * * *', new RunCommand('app:jobs:run')))
            ->add(RecurringMessage::cron('5 * * * *', new RunCommand('app:reminders:send')))
            ->add(RecurringMessage::cron('0 18 * * *', new RunCommand('app:notifications:digest')))
            ->stateful($this->cache)->processOnlyLastMissedRun(true);
    }
}
// + un gestionnaire RunCommand qui appelle chaque commande, et un service Docker :
//   php bin/console messenger:consume scheduler_default --time-limit=3600 --memory-limit=256M
```

## Limites de cette évaluation

- Le prototype est un **squelette minimal**, pas Jobscan : la mémoire d'un worker complet (Doctrine, profils, caches) n'a pas été mesurée sur la durée, ni les fuites éventuelles.
- Aucun essai de longue durée, de plusieurs workers, ni du verrou `lock` de Scheduler.
- Observations faites avec Scheduler 8.1.10 et Messenger 8.1.11 ; le comportement peut évoluer.
