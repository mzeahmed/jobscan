.PHONY: help build up down logs bash migrate run-pipeline alerts fix-perms

COMPOSE = docker compose -f docker-compose.yml
COMPOSE_PROD = docker compose -f docker-compose.yml -f docker-compose.prod.yml

APP_CONTAINER = jobscan_app

help: ## Affiche la liste des commandes disponibles
	@echo ""
	@echo "Usage: make [target]"
	@echo "--------------------------------------------"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf " %-28s %s\n", $$1, $$2}'
	@echo ""

build: ## Build les conteneurs
	$(COMPOSE) build

up: lms-start build ## Démarre les conteneurs
	$(COMPOSE) up -d

up-fast: ## Démarre sans rebuild
	$(COMPOSE) up -d

down: lms-stop ## Stop les conteneurs
	$(COMPOSE) down

restart: down up ## Redémarre les conteneurs

logs: ## Logs des conteneurs
	$(COMPOSE) logs -f

ps: ## Liste les conteneurs
	$(COMPOSE) ps

bash: ## Accède au container app
	docker exec -it $(APP_CONTAINER) bash

# ========================
# SYMFONY
# ========================

console: ## Lance une commande Symfony
	symfony console $(filter-out $@,$(MAKECMDGOALS))

migrate: ## Lance les migrations
	symfony console doctrine:migrations:migrate --no-interaction

run-pipeline: ## Lance le pipeline JOBSCAN
	php bin/console app:jobs:run

# ========================
# LM STUDIO
# ========================

lms-start: ## Démarre le serveur de LM Studio
	lms server start

lms-stop: ## Arrête le serveur de LM Studio
	lms server stop

# ========================
# LOGS UTILES
# ========================

alerts: ## Affiche les alertes JOBSCAN
	tail -f var/alerts.log

pipeline-logs: ## Logs du cron (si configuré)
	tail -f /var/log/jobscan.log

pint: ## Lancement de Laravel Pint
	composer run lint

pintf: ## Lancement de Laravel Pint avec correction
	composer run lint:fix

# ========================
# PERMISSIONS
# ========================

fix-perms: ## Corrige les permissions SQLite
	chmod -R 775 var
