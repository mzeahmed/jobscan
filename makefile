.PHONY: help setup build up down logs bash migrate run-pipeline alerts fix-perms

COMPOSE = docker compose -f docker-compose.yml
COMPOSE_PROD = docker compose -f docker-compose.yml -f docker-compose.prod.yml

APP_CONTAINER = jobscan_app

RED=\033[0;31m
GREEN=\033[0;32m
YELLOW=\033[0;33m
BLUE=\033[0;34m
NO_COLOR=\033[0m

setup: ## Configure le dépôt (git hooks, etc.)
	git config core.hooksPath .githooks
	@echo "$(GREEN)Git hooks configurés → .githooks$(NO_COLOR)"

help: ## Affiche la liste des commandes disponibles
	@echo ""
	@echo "Usage: make [target]"
	@echo "--------------------------------------------"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf " %-28s %s\n", $$1, $$2}'
	@echo ""

build: ## Build les conteneurs
	@echo "$(YELLOW)Construction des conteneurs...$(NO_COLOR)"
	$(COMPOSE) build
	@echo "$(GREEN)Conteneurs construits$(NO_COLOR)"

up: lms-start build ## Démarre les conteneurs
	@echo "$(YELLOW)Démarrage des conteneurs...$(NO_COLOR)"
	$(COMPOSE) up -d
	@echo "$(GREEN)Conteneurs démarrés$(NO_COLOR)"

up-fast: ## Démarre sans rebuild
	@echo "$(YELLOW)Démarrage sans rebuild des conteneurs...$(NO_COLOR)"
	$(COMPOSE) up -d
	@echo "$(GREEN)Conteneurs démarrés$(NO_COLOR)"

down: lms-stop ## Stop les conteneurs
	@echo "$(YELLOW)Arrêt des conteneurs...$(NO_COLOR)"
	$(COMPOSE) down
	@echo "$(GREEN)Conteneurs arrêtés$(NO_COLOR)"

restart: down up ## Redémarre les conteneurs

logs: ## Logs des conteneurs
	@echo "$(YELLOW)Affichage des logs...$(NO_COLOR)"
	$(COMPOSE) logs -f

ps: ## Liste les conteneurs
	@echo "$(YELLOW)Listing des conteneurs...$(NO_COLOR)"
	$(COMPOSE) ps

bash: ## Accède au container app
	@echo "$(YELLOW)Accès au container app...$(NO_COLOR)"
	docker exec -it $(APP_CONTAINER) bash

# ========================
# SYMFONY
# ========================

console: ## Lance une commande Symfony
	symfony console $(filter-out $@,$(MAKECMDGOALS))

migrate: ## Lance les migrations
	@echo "$(YELLOW)Lancement des migrations...$(NO_COLOR)"
	symfony console doctrine:migrations:migrate --no-interaction
	@echo "$(GREEN)Migrations terminées$(NO_COLOR)"

run-pipeline: ## Lance la pipeline JOBSCAN
	@echo "$(YELLOW)Lancement de la pipeline JOBSCAN...$(NO_COLOR)"
	php bin/console app:jobs:run
	@echo "$(GREEN)Pipeline JOBSCAN terminée$(NO_COLOR)"

# ========================
# LM STUDIO
# ========================

lms-start: ## Démarre le serveur de LM Studio
	@echo "$(YELLOW)Démarrage du serveur de LM Studio...$(NO_COLOR)"
	lms server start
	@echo "$(GREEN)Serveur de LM Studio démarré$(NO_COLOR)"

lms-stop: ## Arrête le serveur de LM Studio
	@echo "$(YELLOW)Arrêt du serveur de LM Studio...$(NO_COLOR)"
	lms server stop
	@echo "$(GREEN)Serveur de LM Studio arrêté$(NO_COLOR)"

# ========================
# LOGS / UTILES
# ========================

alerts: ## Affiche les alertes JOBSCAN
	@echo "$(YELLOW)Affichage des alertes JOBSCAN...$(NO_COLOR)"
	tail -f var/alerts.log

pipeline-logs: ## Logs du cron (si configuré)
	@echo "$(YELLOW)Affichage des logs du pipeline...$(NO_COLOR)"
	tail -f /var/log/jobscan.log

pint: ## Lancement de Laravel Pint
	@echo "$(YELLOW)Lancement de Laravel Pint...$(NO_COLOR)"
	composer run lint
	@echo "$(GREEN)Pint terminé$(NO_COLOR)"

pintf: ## Lancement de Laravel Pint avec correction
	@echo "$(YELLOW)Lancement de Laravel Pint avec correction$(NO_COLOR)"
	composer run lint:fix
	@echo "$(GREEN)Pint terminé$(NO_COLOR)"

stan: ## Lancement de PHPStan
	@echo "$(YELLOW)Lancement de PHPStan...$(NO_COLOR)"
	./vendor/bin/phpstan analyse -c phpstan.neon
	@echo "$(GREEN)PHPStan terminé$(NO_COLOR)"

hard: ## Reinitialisation du dépôt (attention, toutes les modifications non commit seront perdues)
	@echo "$(RED)⚠️  Cette action va supprimer toutes les modifications non commitées.$(NO_COLOR)"
	@printf "Confirmer ? [y/N] " && read ans && [ "$$ans" = "y" ] || (echo "Annulé." && exit 1)
	@echo "$(YELLOW)Réinitialisation du dépôt...$(NO_COLOR)"
	git reset --hard
	git clean -fd
	@echo "$(GREEN)Dépôt réinitialisé.$(NO_COLOR)"


# ========================
# TESTES
# ========================
test: ## Lancement des tests PHPUnit
	@echo "$(YELLOW)Lancement des tests PHPUnit...$(NO_COLOR)"
	php bin/phpunit
	@echo "$(GREEN)Tests PHPUnit terminés$(NO_COLOR)"

# ========================
# PERMISSIONS
# ========================

fix-perms: ## Corrige les permissions SQLite
	chmod -R 775 var
