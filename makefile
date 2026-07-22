.PHONY: help setup hosts build up down logs bash migrate run-pipeline alerts fix-perms

COMPOSE = docker compose -f docker-compose.yml
COMPOSE_PROD = docker compose -f docker-compose.yml -f docker-compose.prod.yml

APP_CONTAINER = jobscan_app

DOMAINS = jobscan.local searxng.local

RED=\033[0;31m
GREEN=\033[0;32m
YELLOW=\033[0;33m
BLUE=\033[0;34m
NO_COLOR=\033[0m

setup: ## Configure le dépôt (git hooks, etc.)
	git config core.hooksPath .githooks
	@echo "$(GREEN)Git hooks configurés → .githooks$(NO_COLOR)"

hosts: ## Ajoute les domaines locaux dans /etc/hosts (nécessite sudo)
	@echo "$(YELLOW)Mise à jour de /etc/hosts...$(NO_COLOR)"
	@for domain in $(DOMAINS); do \
		if grep -qE "^127\.0\.0\.1[[:space:]]+$$domain$$" /etc/hosts; then \
			echo "$(GREEN)$$domain déjà présent$(NO_COLOR)"; \
		else \
			echo "127.0.0.1 $$domain" | sudo tee -a /etc/hosts > /dev/null; \
			echo "$(GREEN)$$domain ajouté$(NO_COLOR)"; \
		fi; \
	done

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

up: build ## Démarre les conteneurs
	@echo "$(YELLOW)Démarrage des conteneurs...$(NO_COLOR)"
	$(COMPOSE) up -d
	@echo "$(GREEN)Conteneurs démarrés$(NO_COLOR)"
	@echo "$(BLUE)Dashboard Traefik: http://localhost:9080$(NO_COLOR)"
	@echo "$(BLUE)Application: https://jobscan.local:8443/job$(NO_COLOR)"
	@echo "$(BLUE)SearXNG: https://searxng.local:8443$(NO_COLOR)"

up-fast: ## Démarre sans rebuild
	@echo "$(YELLOW)Démarrage sans rebuild des conteneurs...$(NO_COLOR)"
	$(COMPOSE) up -d
	@echo "$(GREEN)Conteneurs démarrés$(NO_COLOR)"
	@echo "$(BLUE)Dashboard Traefik: http://localhost:9080$(NO_COLOR)"
	@echo "$(BLUE)Application: https://jobscan.local:8443/job$(NO_COLOR)"
	@echo "$(BLUE)SearXNG: https://searxng.local:8443$(NO_COLOR)"

down: ## Stop les conteneurs
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
	cd app && symfony console $(filter-out $@,$(MAKECMDGOALS))

migrate: ## Lance les migrations
	@echo "$(YELLOW)Lancement des migrations...$(NO_COLOR)"
	cd app && symfony console doctrine:migrations:migrate --no-interaction
	@echo "$(GREEN)Migrations terminées$(NO_COLOR)"

run-pipeline: ## Lance la pipeline JOBSCAN
	@echo "$(YELLOW)Lancement de la pipeline JOBSCAN...$(NO_COLOR)"
	cd app && php bin/console app:jobs:run
	@echo "$(GREEN)Pipeline JOBSCAN terminée$(NO_COLOR)"

# ========================
# Assets
# ========================
w: ## Lance le watcher TypeScript
	@echo "$(YELLOW)Lancement du watcher TypeScript...$(NO_COLOR)"
	cd app && php bin/console typescript:build --watch

b: ## Build les assets TypeScript
	@echo "$(YELLOW)Build des assets TypeScript...$(NO_COLOR)"
	cd app && php bin/console typescript:build
	cd app && php bin/console asset-map:compile
	@echo "$(GREEN)Build terminé$(NO_COLOR)"

# ========================
# LOGS / UTILES
# ========================

alerts: ## Affiche les alertes JOBSCAN
	@echo "$(YELLOW)Affichage des alertes JOBSCAN...$(NO_COLOR)"
	tail -f app/var/alerts.log

pipeline-logs: ## Logs du cron (si configuré)
	@echo "$(YELLOW)Affichage des logs du pipeline...$(NO_COLOR)"
	tail -f /var/log/jobscan.log

cs: ## Lancement de php-cs-fixer en mode test
	@echo "$(YELLOW)Lancement de php-cs-fixer...$(NO_COLOR)"
	cd app && composer run lint
	@echo "$(GREEN)php-cs-fixer terminé$(NO_COLOR)"

csf: ## Lancement de php-cs-fixer avec correction
	@echo "$(YELLOW)Lancement de php-cs-fixer avec correction$(NO_COLOR)"
	cd app && composer run lint:fix
	@echo "$(GREEN)php-cs-fixer terminé$(NO_COLOR)"

stan: ## Lancement de PHPStan
	@echo "$(YELLOW)Lancement de PHPStan...$(NO_COLOR)"
	cd app && ./vendor/bin/phpstan analyse -c phpstan.neon
	@echo "$(GREEN)PHPStan terminé$(NO_COLOR)"

rector: ## Appliquer les transformations de Rector
	@echo "$(YELLOW)Application des transformations de Rector...$(NO_COLOR)"
	cd app && ./vendor/bin/rector process
	@echo "$(GREEN)Transformations de Rector appliquées$(NO_COLOR)"

rector-check: ## Vérifie les transformations de Rector sans les appliquer
	@echo "$(YELLOW)Vérification des transformations de Rector...$(NO_COLOR)"
	cd app && ./vendor/bin/rector process --dry-run
	@echo "$(GREEN)Vérification des transformations de Rector terminée$(NO_COLOR)"

hard: ## Reinitialisation du dépôt (attention, toutes les modifications non commit seront perdues)
	@echo "$(RED)⚠️  Cette action va supprimer toutes les modifications non commitées.$(NO_COLOR)"
	@printf "Confirmer ? [y/N] " && read ans && [ "$$ans" = "y" ] || (echo "Annulé." && exit 1)
	@echo "$(YELLOW)Réinitialisation du dépôt...$(NO_COLOR)"
	git reset --hard
	git clean -fd
	@echo "$(GREEN)Dépôt réinitialisé.$(NO_COLOR)"

clean: ## Supprimer toutes les branches locales et distantes sauf main
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


# ========================
# TESTES
# ========================
test: ## Lancement des tests PHPUnit
	@echo "$(YELLOW)Lancement des tests PHPUnit...$(NO_COLOR)"
	cd app && php bin/phpunit
	@echo "$(GREEN)Tests PHPUnit terminés$(NO_COLOR)"

# ========================
# PERMISSIONS
# ========================

fix-perms: ## Corrige les permissions SQLite/cache (utile après make up, php-fpm tourne en www-data)
	sudo chmod -R 777 app/var
