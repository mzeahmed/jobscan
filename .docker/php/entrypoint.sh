#!/bin/sh
# Démarre php-fpm, et, si JOBSCAN_AUTO_MIGRATE=1, applique d'abord les migrations Doctrine (non destructives).
# Seul le service « app » (php-fpm) migre : les services de travail (cron, telegram) attendent qu'il soit sain.
set -eu

if [ "${1:-}" = "php-fpm" ] && [ "${JOBSCAN_AUTO_MIGRATE:-0}" = "1" ]; then
    echo "[jobscan] Migrations de base de données…"
    php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

exec "$@"
