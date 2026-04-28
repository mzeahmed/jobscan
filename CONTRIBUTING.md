# Contribuer à JOBSCAN

Merci de l'intérêt porté au projet. Ce guide explique comment installer, lancer et contribuer à JOBSCAN.

---

## Prérequis

- PHP 8.2+
- Composer
- Symfony CLI
- SQLite
- [LM Studio](https://lmstudio.ai/) avec un modèle chargé (ex. `google/gemma-3-4b-it`)
- [SearXNG](https://docs.searxng.org/) en local ou via Docker

---

## Installation

```bash
git clone https://github.com/mzeahmed/jobscan.git
cd jobscan

# Configurer git hooks
make setup

# Installer les dépendances
composer install

# Copier et adapter la configuration
cp .env .env.local
# → Renseigner TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID, AI_API_BASE, etc.

# Créer la base de données et appliquer les migrations
symfony console doctrine:migrations:migrate --no-interaction
```

---

## Lancer le pipeline

```bash
# Exécution complète (RSS + SearXNG → IA → score → alerte)
make run-pipeline

# Ou directement
symfony console app:jobs:run
```

---

## Avant de soumettre une PR

1. **PHPStan** — analyse statique, niveau configuré dans `phpstan.neon`
   ```bash
   make stan
   ```

2. **Pint** — vérifier le style de code (PSR-12 + règles Laravel Pint)
   ```bash
   make pint       # vérification
   make pintf      # correction automatique
   ```

3. S'assurer que le pipeline tourne sans erreur en local.

Le hook `pre-push` lance PHPStan automatiquement. Si le hook ne se déclenche pas, lancer `make setup`.

---

## Types de contributions attendues

| Type | Description |
|------|-------------|
| Bug fix | Corriger un comportement incorrect ou une erreur |
| Nouveau provider | Ajouter une source d'offres (RSS, API, scraping) |
| Amélioration du scoring | Affiner les règles heuristiques ou le prompt IA |
| Tests | Ajouter des tests unitaires ou d'intégration |
| Documentation | Améliorer README, CONTRIBUTING, ROADMAP |
| CI/CD | GitHub Actions, automatisation |

---

## Ajouter un provider

Un provider implémente `JobProviderInterface` et retourne un tableau de `JobDTO`.

```php
// src/Service/Provider/MonProvider.php
final class MonProvider implements JobProviderInterface
{
    public function fetch(): array
    {
        // Récupérer et retourner des JobDTO
    }
}
```

Taguer le service dans `config/services.yaml` :

```yaml
App\Service\Provider\MonProvider:
    tags: ['app.job_provider']
```

---

## Style de code

- PHP 8.2+ — utiliser les types natifs, `readonly`, les attributs Symfony
- `declare(strict_types=1)` dans chaque fichier
- Classes `final` par défaut, sauf héritage nécessaire
- Pas de commentaires évidents — le code doit se lire seul
- Un seul niveau de responsabilité par classe (SRP)

---

## Workflow Git

1. Forker le repo
2. Créer une branche depuis `main` : `git checkout -b feat/mon-provider`
3. Committer avec des messages clairs : `feat: add LinkedIn provider`
4. Pousser et ouvrir une Pull Request

Utiliser les prefixes : `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`