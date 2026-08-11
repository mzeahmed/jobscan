.PHONY: help setup hosts build up down logs bash migrate run-pipeline llm-load llm-status alerts fix-perms test test-unit test-integration

COMPOSE = docker compose -f docker-compose.yml
COMPOSE_PROD = docker compose -f docker-compose.yml -f docker-compose.prod.yml

# Le projet requiert PHP >= 8.4.1 : on prend php8.4 s'il existe, sinon le php par défaut.
# Surchargeable : make run-pipeline PHP=php8.5
PHP ?= $(shell command -v php8.4 2>/dev/null || command -v php)
COMPOSER ?= $(PHP) $(shell command -v composer)

APP_CONTAINER = jobscan_app
LMSTUDIO_MODEL_KEY ?= qwen/qwen3-8b
LMSTUDIO_MODEL_ID ?= qwen3:8b
RESET ?= 0
PIPELINE_ARGS ?=

DOMAINS = jobscan.local searxng.local

RED=\033[0;31m
GREEN=\033[0;32m
YELLOW=\033[0;33m
BLUE=\033[0;34m
NO_COLOR=\033[0m

SCRIPTS_DIR=./app/tools/scripts

setup: ## Configure le dépôt (git hooks, etc.) — Exemple : make setup
	git config core.hooksPath .githooks
	@echo "$(GREEN)Git hooks configurés → .githooks$(NO_COLOR)"

hosts: ## Ajoute les domaines locaux dans /etc/hosts (nécessite sudo) — Exemple : make hosts
	@echo "$(YELLOW)Mise à jour de /etc/hosts...$(NO_COLOR)"
	@for domain in $(DOMAINS); do \
		if grep -qE "^127\.0\.0\.1[[:space:]]+$$domain$$" /etc/hosts; then \
			echo "$(GREEN)$$domain déjà présent$(NO_COLOR)"; \
		else \
			echo "127.0.0.1 $$domain" | sudo tee -a /etc/hosts > /dev/null; \
			echo "$(GREEN)$$domain ajouté$(NO_COLOR)"; \
		fi; \
	done

help: ## Affiche la liste des commandes disponibles — Exemple : make help
	@echo ""
	@echo "Usage: make [target]"
	@echo "--------------------------------------------"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf " %-28s %s\n", $$1, $$2}'
	@echo ""

build: ## Build les conteneurs — Exemple : make build
	@echo "$(YELLOW)Construction des conteneurs...$(NO_COLOR)"
	$(COMPOSE) build
	@echo "$(GREEN)Conteneurs construits$(NO_COLOR)"

up: build ## Démarre les conteneurs — Exemple : make up
	@echo "$(YELLOW)Démarrage des conteneurs...$(NO_COLOR)"
	$(COMPOSE) up -d
	@echo "$(GREEN)Conteneurs démarrés$(NO_COLOR)"
	@echo "$(BLUE)Dashboard Traefik: http://localhost:9080$(NO_COLOR)"
	@echo "$(BLUE)Application: https://jobscan.local:8443/job$(NO_COLOR)"
	@echo "$(BLUE)SearXNG: https://searxng.local:8443$(NO_COLOR)"

up-fast: ## Démarre sans rebuild — Exemple : make up-fast
	@echo "$(YELLOW)Démarrage sans rebuild des conteneurs...$(NO_COLOR)"
	$(COMPOSE) up -d
	@echo "$(GREEN)Conteneurs démarrés$(NO_COLOR)"
	@echo "$(BLUE)Dashboard Traefik: http://localhost:9080$(NO_COLOR)"
	@echo "$(BLUE)Application: https://jobscan.local:8443/job$(NO_COLOR)"
	@echo "$(BLUE)SearXNG: https://searxng.local:8443$(NO_COLOR)"

down: ## Stop les conteneurs — Exemple : make down
	@echo "$(YELLOW)Arrêt des conteneurs...$(NO_COLOR)"
	$(COMPOSE) down
	@echo "$(GREEN)Conteneurs arrêtés$(NO_COLOR)"

restart: down up ## Redémarre les conteneurs — Exemple : make restart

logs: ## Logs des conteneurs — Exemple : make logs
	@echo "$(YELLOW)Affichage des logs...$(NO_COLOR)"
	$(COMPOSE) logs -f

ps: ## Liste les conteneurs — Exemple : make ps
	@echo "$(YELLOW)Listing des conteneurs...$(NO_COLOR)"
	$(COMPOSE) ps

bash: ## Accède au container app — Exemple : make bash
	@echo "$(YELLOW)Accès au container app...$(NO_COLOR)"
	docker exec -it $(APP_CONTAINER) bash

# ========================
# SYMFONY
# ========================

console: ## Affiche les commandes Symfony — Exemple : make console
	cd app && symfony console $(filter-out $@,$(MAKECMDGOALS))

migrate: ## Lance les migrations — Exemple : make migrate
	@echo "$(YELLOW)Lancement des migrations...$(NO_COLOR)"
	cd app && symfony console doctrine:migrations:migrate --no-interaction
	@echo "$(GREEN)Migrations terminées$(NO_COLOR)"

run-pipeline: ## Lance la pipeline JOBSCAN — Exemple : make run-pipeline RESET=1
	@echo "$(YELLOW)Lancement de la pipeline JOBSCAN...$(NO_COLOR)"
	cd app && $(PHP) bin/console app:jobs:run $(if $(filter 1 true yes,$(RESET)),--reset,) $(PIPELINE_ARGS)
	@echo "$(GREEN)Pipeline JOBSCAN terminée$(NO_COLOR)"

llm-load: ## Charge le modèle LM Studio utilisé par JOBSCAN — Exemple : make llm-load
	@command -v lms >/dev/null 2>&1 || (echo "$(RED)CLI LM Studio 'lms' introuvable.$(NO_COLOR)" && exit 1)
	@if lms ps --json | grep -Fq '$(LMSTUDIO_MODEL_ID)'; then \
		echo "$(GREEN)Modèle LM Studio déjà chargé : $(LMSTUDIO_MODEL_ID)$(NO_COLOR)"; \
	else \
		echo "$(YELLOW)Chargement de $(LMSTUDIO_MODEL_KEY)...$(NO_COLOR)"; \
		lms load '$(LMSTUDIO_MODEL_KEY)' --identifier '$(LMSTUDIO_MODEL_ID)' --yes; \
	fi

llm-status: ## Affiche les modèles actuellement chargés dans LM Studio — Exemple : make llm-status
	@command -v lms >/dev/null 2>&1 || (echo "$(RED)CLI LM Studio 'lms' introuvable.$(NO_COLOR)" && exit 1)
	lms ps

# ========================
# Assets
# ========================
w: ## Lance le watcher TypeScript — Exemple : make w
	@echo "$(YELLOW)Lancement du watcher TypeScript...$(NO_COLOR)"
	cd app && $(PHP) bin/console typescript:build --watch

b: ## Build les assets TypeScript — Exemple : make b
	@echo "$(YELLOW)Build des assets TypeScript...$(NO_COLOR)"
	cd app && $(PHP) bin/console typescript:build
	cd app && $(PHP) bin/console asset-map:compile
	@echo "$(GREEN)Build terminé$(NO_COLOR)"

# ========================
# LOGS / UTILES
# ========================

alerts: ## Affiche les alertes JOBSCAN — Exemple : make alerts
	@echo "$(YELLOW)Affichage des alertes JOBSCAN...$(NO_COLOR)"
	tail -f app/var/alerts.log

pipeline-logs: ## Logs du cron (si configuré) — Exemple : make pipeline-logs
	@echo "$(YELLOW)Affichage des logs du pipeline...$(NO_COLOR)"
	tail -f /var/log/jobscan.log

cs: ## Lancement de php-cs-fixer en mode test — Exemple : make cs
	@echo "$(YELLOW)Lancement de php-cs-fixer...$(NO_COLOR)"
	cd app && $(COMPOSER) run lint
	@echo "$(GREEN)php-cs-fixer terminé$(NO_COLOR)"

csf: ## Lancement de php-cs-fixer avec correction — Exemple : make csf
	@echo "$(YELLOW)Lancement de php-cs-fixer avec correction$(NO_COLOR)"
	cd app && $(COMPOSER) run lint:fix
	@echo "$(GREEN)php-cs-fixer terminé$(NO_COLOR)"

stan: ## Lancement de PHPStan — Exemple : make stan
	@echo "$(YELLOW)Lancement de PHPStan...$(NO_COLOR)"
	cd app && $(PHP) ./vendor/bin/phpstan analyse -c phpstan.neon
	@echo "$(GREEN)PHPStan terminé$(NO_COLOR)"

rector: ## Applique les transformations de Rector — Exemple : make rector
	@echo "$(YELLOW)Application des transformations de Rector...$(NO_COLOR)"
	cd app && $(PHP) ./vendor/bin/rector process
	@echo "$(GREEN)Transformations de Rector appliquées$(NO_COLOR)"

rector-check: ## Vérifie Rector sans appliquer les changements — Exemple : make rector-check
	@echo "$(YELLOW)Vérification des transformations de Rector...$(NO_COLOR)"
	cd app && $(PHP) ./vendor/bin/rector process --dry-run
	@echo "$(GREEN)Vérification des transformations de Rector terminée$(NO_COLOR)"

hard: ## Réinitialise le dépôt (destructif : changements perdus) — Exemple : make hard
	@echo "$(RED)⚠️  Cette action va supprimer toutes les modifications non commitées.$(NO_COLOR)"
	@printf "Confirmer ? [y/N] " && read ans && [ "$$ans" = "y" ] || (echo "Annulé." && exit 1)
	@echo "$(YELLOW)Réinitialisation du dépôt...$(NO_COLOR)"
	git reset --hard
	git clean -fd
	@echo "$(GREEN)Dépôt réinitialisé.$(NO_COLOR)"

clean: ## Supprime toutes les branches sauf main (destructif) — Exemple : make clean
	@echo "$(YELLOW)Branches locales à supprimer :$(NO_COLOR)"
	@git branch | grep -vE '^\*|main' || echo "  (aucune)"
	@echo "$(YELLOW)Branches distantes à supprimer :$(NO_COLOR)"
	@git fetch --prune -q && git branch -r | grep -vE 'origin/(main)' | sed 's/origin\///' || echo "  (aucune)"
	@echo ""
	@printf "$(RED)⚠️  Confirmer la suppression ? [y/N] $(NO_COLOR)" && read ans && [ "$${ans}" = "y" ] || { echo "$(YELLOW)Annulé.$(NO_COLOR)"; exit 1; }

	@echo "$(YELLOW)Nettoyage des références distantes obsolètes...$(NO_COLOR)"
	@git fetch --prune

	@echo "$(YELLOW)Suppression des branches locales...$(NO_COLOR)"
	@git branch | grep -vE '^\*|main' | xargs -r git branch -D || true

	@#echo "$(YELLOW)Suppression des branches distantes...$(NO_COLOR)"
	@#git branch -r | grep -vE 'origin/(main)' | sed 's/origin\///' | xargs -r -I {} git push origin --delete {} || true

	@echo "$(GREEN)Nettoyage des branches terminé$(NO_COLOR)"

# ==============================================================================
# Fork
# ==============================================================================

# BRANCH accepts BRANCH=, branch= B= or b= (defaults to main)
BRANCH := $(or $(BRANCH),$(branch),$(B),$(b),main)

upstream-add: ## Ajoute le dépôt upstream — Exemple : make upstream-add URL=git@github.com:owner/repo.git
	@test -n "$(URL)" || (echo "Usage: make upstream-add URL=git@github.com:owner/repo.git" && exit 1)
	git remote add upstream $(URL)
	@echo "$(GREEN)✓ Upstream remote added$(RESET)"

sync-upstream: ## Fusionne upstream dans la branche — Exemple : make sync-upstream BRANCH=main
	git fetch upstream
	git merge upstream/$(BRANCH)
	@echo "$(GREEN)✓ Branch synced with upstream/$(BRANCH)$(RESET)"

sync-upstream-rebase: ## Rebase sur upstream — Exemple : make sync-upstream-rebase BRANCH=main
	git fetch upstream
	git rebase upstream/$(BRANCH)
	@echo "$(GREEN)✓ Branch rebased onto upstream/$(BRANCH)$(RESET)"

push-fork: ## Pousse la branche vers origin — Exemple : make push-fork BRANCH=main
	git push origin $(BRANCH)
	@echo "$(GREEN)✓ Pushed to origin/$(BRANCH)$(RESET)"

# ========================
# TESTES
# ========================
test: ## Lance les tests PHPUnit — Exemple : make test
	@echo "$(YELLOW)Lancement des tests PHPUnit...$(NO_COLOR)"
	cd app && $(PHP) bin/phpunit
	@echo "$(GREEN)Tests PHPUnit terminés$(NO_COLOR)"

test-unit: ## Lance uniquement les tests unitaires — Exemple : make test-unit
	@echo "$(YELLOW)Lancement des tests unitaires...$(NO_COLOR)"
	cd app && $(PHP) bin/phpunit --testsuite Unit
	@echo "$(GREEN)Tests unitaires terminés$(NO_COLOR)"

test-integration: ## Lance uniquement les tests d'intégration — Exemple : make test-integration
	@echo "$(YELLOW)Lancement des tests d'intégration...$(NO_COLOR)"
	cd app && $(PHP) bin/phpunit --testsuite Integration
	@echo "$(GREEN)Tests d'intégration terminés$(NO_COLOR)"

commit: ## Commit rapide (make commit m="message" b=branche)
	@echo "$(YELLOW)Ajout des modifications et commit (${m}) ...$(NO_COLOR)"
	@$(SCRIPTS_DIR)/commit.sh "$(m)" $(b)

# ========================
# PERMISSIONS
# ========================

fix-perms: ## Corrige les permissions SQLite/cache — Exemple : make fix-perms
	sudo chmod -R 777 app/var
