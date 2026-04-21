COMPOSE_DEV = docker compose -f docker-compose.yml -f docker-compose-override.yml
COMPOSE_PROD = docker compose -f docker-compose.yml -f docker-compose.prod.yml

APP_CONTAINER = oppscan_app

help: ## Affiche la liste des commandes disponibles
	@echo ""
	@echo "Usage: make [target]"
	@echo "--------------------------------------------"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf " %-28s %s\n", $$1, $$2}'
	@echo ""

# ========================
# DEV
# ========================

build: ## Build les conteneurs (DEV)
	$(COMPOSE_DEV) build

up: build ## Démarre les conteneurs (DEV)
	$(COMPOSE_DEV) up -d

up-fast: ## Démarre sans rebuild (DEV)
	$(COMPOSE_DEV) up -d

down: ## Stop les conteneurs (DEV)
	$(COMPOSE_DEV) down

restart: down up ## Redémarre les conteneurs (DEV)

logs: ## Logs des conteneurs (DEV)
	$(COMPOSE_DEV) logs -f

ps: ## Liste les conteneurs (DEV)
	$(COMPOSE_DEV) ps

bash: ## Accède au container app (DEV)
	docker exec -it $(APP_CONTAINER) bash

# ========================
# PROD
# ========================

build-prod: ## Build les conteneurs (PROD)
	$(COMPOSE_PROD) build

up-prod: build-prod ## Démarre les conteneurs (PROD)
	$(COMPOSE_PROD) up -d

up-prod-fast: ## Démarre sans rebuild (PROD)
	$(COMPOSE_PROD) up -d

down-prod: ## Stop les conteneurs (PROD)
	$(COMPOSE_PROD) down

logs-prod: ## Logs des conteneurs (PROD)
	$(COMPOSE_PROD) logs -f

# ========================
# SYMFONY
# ========================

console: ## Lance une commande Symfony
	symfony console $(filter-out $@,$(MAKECMDGOALS))

migrate: ## Lance les migrations
	symfony console doctrine:migrations:migrate --no-interaction

run-pipeline: ## Lance le pipeline OPPSCAN
	php bin/console app:jobs:run

# ========================
# LOGS UTILES
# ========================

alerts: ## Affiche les alertes OPPSCAN
	tail -f var/alerts.log

pipeline-logs: ## Logs du cron (si configuré)
	tail -f /var/log/oppscan.log

pint: ## Lancement de Laravel Pint
	composer run lint

pintf: ## Lancement de Laravel Pint avec correction
	composer run lint:fix

# ========================
# PERMISSIONS
# ========================

fix-perms: ## Corrige les permissions SQLite
	chmod -R 775 var
