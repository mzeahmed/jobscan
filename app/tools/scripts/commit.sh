#!/bin/bash
set -euo pipefail

# Couleurs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# -------------------------------------------------------------------------
# Spinner — attend un PID donné via kill -0 (aucun fork ps|awk|grep à chaque tick)
# -------------------------------------------------------------------------
spinner() {
  local pid="$1"
  local delay=0.1
  local spinstr="|/-\\"
  while kill -0 "$pid" 2>/dev/null; do
    local temp=${spinstr#?}
    printf " [%c]  " "$spinstr"
    spinstr=$temp${spinstr%"$temp"}
    sleep "$delay"
    printf "\b\b\b\b\b\b"
  done
  printf "    \b\b\b\b"
}

# -------------------------------------------------------------------------
# run_step <label> <répertoire> <commande...> — lance en arrière-plan, affiche le spinner,
# récupère le vrai code retour de la commande (via wait), affiche le log
# capturé uniquement en cas d'échec, et stoppe le script si ça échoue.
# -------------------------------------------------------------------------
run_step() {
  local label="$1"
  local working_dir="$2"
  shift 2

  echo -e "${CYAN}${label}...${NC}"

  local log
  log="$(mktemp)"

  (
    cd "$working_dir"
    "$@"
  ) >"$log" 2>&1 &
  local pid=$!
  spinner "$pid"

  local status=0
  wait "$pid" || status=$?

  if [ "$status" -ne 0 ]; then
    echo -e "${RED}❌ ${label} échoué :${NC}"
    cat "$log"
    rm -f "$log"
    echo -e "${RED}❌ Commit/push annulés.${NC}"
    exit 1
  fi

  rm -f "$log"
  echo -e "${GREEN}✅ ${label} OK.${NC}"
}

# -------------------------------------------------------------------------
# require_binary <chemin> — vérifie qu'un exécutable requis est bien présent
# -------------------------------------------------------------------------
require_binary() {
  local path="$1"
  if [ ! -x "$path" ]; then
    echo -e "${RED}❌ Outil manquant ou non exécutable : ${path}${NC}"
    echo -e "${YELLOW}As-tu lancé 'composer install' ?${NC}"
    exit 1
  fi
}

# Vérification des arguments
COMMIT_MESSAGE="${1:-}"
BRANCH_NAME="${2:-}"

if [ -z "$COMMIT_MESSAGE" ]; then
  echo -e "${RED}❌ Merci de préciser le message du commit en argument.${NC}"
  echo -e "${YELLOW}Exemple : app/tools/scripts/commit.sh \"test: separate unit and integration test suites\" ma-branche${NC}"
  exit 1
fi

if [ -z "$BRANCH_NAME" ]; then
  echo -e "${RED}❌ Merci de préciser le nom de la branche en argument.${NC}"
  echo -e "${YELLOW}Exemple : app/tools/scripts/commit.sh \"test: separate unit and integration test suites\" ma-branche${NC}"
  exit 1
fi

# Se placer à la racine du repo (au cas où)
REPO_ROOT="$(git rev-parse --show-toplevel)"
APP_DIR="$REPO_ROOT/app"
cd "$REPO_ROOT"

if [ -n "${PHP:-}" ]; then
  PHP_COMMAND="$PHP"
elif command -v php8.4 >/dev/null 2>&1; then
  PHP_COMMAND="php8.4"
else
  PHP_COMMAND="php"
fi

PHP_BIN="$(command -v "$PHP_COMMAND" 2>/dev/null || true)"
if [ -z "$PHP_BIN" ]; then
  echo -e "${RED}❌ PHP est introuvable : ${PHP_COMMAND}${NC}"
  exit 1
fi

# Vérification de la branche courante
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$BRANCH_NAME" != "$CURRENT_BRANCH" ]; then
  echo -e "${YELLOW}⚠️  Attention : la branche courante est '${CURRENT_BRANCH}', mais tu as demandé '${BRANCH_NAME}'.${NC}"
  exit 1
fi

# -------------------------------------------------------------------------
# Vérification des outils requis (fail-fast avant toute étape)
# -------------------------------------------------------------------------
require_binary "$PHP_BIN"
require_binary "$APP_DIR/vendor/bin/rector"
require_binary "$APP_DIR/vendor/bin/php-cs-fixer"
require_binary "$APP_DIR/vendor/bin/phpstan"
require_binary "$APP_DIR/vendor/bin/phpunit"

# -------------------------------------------------------------------------
# Rector → PHP-CS-Fixer → PHPStan → PHPUnit → Build
# -------------------------------------------------------------------------
run_step "🔧 Rector" "$APP_DIR" "$PHP_BIN" vendor/bin/rector process
run_step "🎨 PHP-CS-Fixer" "$APP_DIR" "$PHP_BIN" vendor/bin/php-cs-fixer fix
run_step "🔍 PHPStan" "$APP_DIR" "$PHP_BIN" vendor/bin/phpstan analyse -c phpstan.neon
run_step "🧪 Tests unitaires" "$APP_DIR" "$PHP_BIN" vendor/bin/phpunit --testsuite Unit
run_step "🧪 Tests d'intégration" "$APP_DIR" "$PHP_BIN" vendor/bin/phpunit --testsuite Integration
run_step "🏗️  Build" "$REPO_ROOT" make b

# -------------------------------------------------------------------------
# Commit
# -------------------------------------------------------------------------
echo -e "${CYAN}📦 Ajout des modifications et commit...${NC}"
git add .
# Évite l'erreur si aucun changement :
if git diff --cached --quiet; then
  echo -e "${YELLOW}ℹ️  Aucun changement à committer. Rien poussé.${NC}"
  exit 0
fi
git commit -m "$COMMIT_MESSAGE"
echo -e "${GREEN}✅ Commit effectué.${NC}"

# -------------------------------------------------------------------------
# Push
# -------------------------------------------------------------------------
echo -e "${CYAN}🚀 Push vers origin/${BRANCH_NAME}...${NC}"
git push origin "$BRANCH_NAME"
echo -e "${GREEN}✅ Push effectué.${NC}"
