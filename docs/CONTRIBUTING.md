# Contribuer à JOBSCAN

Merci de l'intérêt porté au projet. Ce guide explique comment installer, lancer et contribuer à JOBSCAN.

---

## Prérequis

- PHP 8.4.1+ (contrainte de Symfony 8.1 ; voir [TROUBLESHOOTING.md](TROUBLESHOOTING.md) si le `php` par défaut de la machine est plus ancien)
- Composer
- Symfony CLI
- SQLite
- [Ollama](https://ollama.com/) avec un modèle téléchargé (recommandé), ou LM Studio / une clé Gemini
- [SearXNG](https://docs.searxng.org/) en local ou via Docker

---

## Installation

```bash
git clone https://github.com/mzeahmed/jobscan.git
cd jobscan

# Configurer git hooks
make setup

# Installer les dépendances
cd app

# .env est ignoré par Git : le créer depuis le modèle AVANT composer install
cp .env.example .env
composer install

# Surcharges propres à la machine (ignorées par Git)
# → Renseigner TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID, OLLAMA_BASE_URL, etc. dans .env.local

# Créer la base de données et appliquer les migrations
php bin/console doctrine:migrations:migrate --no-interaction
```

---

## Lancer le pipeline

```bash
# Exécution complète (RSS + RemoteOK + SearXNG → IA → score → alerte)
make run-pipeline

# Ou directement
cd app && symfony console app:jobs:run
```

---

## Avant de soumettre une PR

1. **PHPStan** — analyse statique, niveau configuré dans `app/phpstan.neon`

   ```bash
   make stan
   ```

2. **PHP-CS-Fixer** — vérifier le style de code (PSR-12 + règles projet)

   ```bash
   make lint       # vérification
   make lintf      # correction automatique
   ```

3. **Rector** — vérifier les transformations de modernisation du code

   ```bash
   make rector     # vérification (simulation)
   make rectorf    # application
   ```

4. **Tests** — placer les tests isolés dans `app/tests/Unit/` et les tests utilisant
   le kernel, une base de données ou plusieurs services dans `app/tests/Integration/`.

   ```bash
   make test-unit          # tests unitaires uniquement
   make test-integration   # tests d'intégration uniquement
   make test               # suite complète
   ```

5. **Changelog** — ajouter tout changement visible dans la section `Unreleased`
   de [CHANGELOG.md](CHANGELOG.md), sous une date ISO `YYYY-MM-DD`, puis dans
   la catégorie `Added`, `Changed` ou `Fixed` appropriée.

6. S'assurer que le pipeline tourne sans erreur en local.

Les hooks lancent PHP-CS-Fixer et PHPStan au commit, puis PHPUnit au push. S'ils ne se déclenchent pas, lancer `make setup`.

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

Un provider implémente `App\Provider\JobProviderInterface` et retourne des `JobDto`. Il ne propage jamais d'exception vers le pipeline.

```php
// app/src/Provider/MonProvider.php
final class MonProvider implements JobProviderInterface
{
    public function name(): string
    {
        return 'mon-provider';
    }

    public function isHealthy(): bool
    {
        // Sonde légère : false si la source est injoignable
    }

    /** @return JobDto[] */
    public function fetch(): array
    {
        // Récupérer et retourner des JobDto
    }
}
```

Le service est tagué `app.job_provider` automatiquement (`_instanceof` dans `config/services.yaml`) : seuls ses arguments, s'il en a, sont à déclarer.

---

## Style de code

- PHP 8.4+ — utiliser les types natifs, `readonly`, les attributs Symfony
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
