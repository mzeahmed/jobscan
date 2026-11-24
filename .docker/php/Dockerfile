# syntax=docker/dockerfile:1

# ── Socle commun ────────────────────────────────────────────────────────────────────────────────────
FROM php:8.4-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip curl ca-certificates libzip-dev libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

# pcntl : arrêt propre (SIGTERM) des services de longue durée, dont l'écoute Telegram.
RUN docker-php-ext-install zip xml opcache pcntl

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# supercronic : planificateur fait pour les conteneurs (sans root, journaux sur la sortie standard). Version et empreinte épinglées.
ARG TARGETARCH
ARG SUPERCRONIC_VERSION=v0.2.51
ARG SUPERCRONIC_SHA256_AMD64=cc232108374e8e2b537045f2d5eaa1229f619db314c3ba6a14d38b35dc0aa123
ARG SUPERCRONIC_SHA256_ARM64=4ae22576a78223d8ccdf5f3b3660a5cbb5288416bf0ca96afc4a36d195932b94
RUN set -eu; \
    case "${TARGETARCH:-amd64}" in \
        amd64) sha="${SUPERCRONIC_SHA256_AMD64}" ;; \
        arm64) sha="${SUPERCRONIC_SHA256_ARM64}" ;; \
        *) echo "Architecture non prise en charge : ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /usr/local/bin/supercronic "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${TARGETARCH:-amd64}"; \
    echo "${sha}  /usr/local/bin/supercronic" | sha256sum -c -; \
    chmod +x /usr/local/bin/supercronic

WORKDIR /app

# ── Développement : le code est monté (./app:/app) et le conteneur tourne sous VOTRE identifiant (voir docker-compose.yml) ───
# Rien n'est copié ici : les fichiers créés dans le volume vous appartiennent, jamais à root.
FROM base AS dev
ENV HOME=/tmp COMPOSER_HOME=/tmp/composer

# ── Construction de production ───────────────────────────────────────────────────────────────────────
FROM base AS build
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY app /app

# Le .env de l'hôte est exclu du contexte : l'image part toujours du modèle versionné. Les vrais identifiants arrivent à l'exécution.
RUN cp .env.example .env \
    && APP_ENV=prod composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction \
    && APP_ENV=prod php bin/console typescript:build \
    && APP_ENV=prod php bin/console asset-map:compile \
    && APP_ENV=prod php bin/console cache:warmup

# ── Production : code figé dans l'image, utilisateur sans privilège, aucun volume de code ─────────────────────
FROM base AS prod

RUN useradd --uid 10001 --create-home --shell /usr/sbin/nologin app

COPY .docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache-prod.ini
COPY .docker/php/entrypoint.sh /usr/local/bin/jobscan-entrypoint
COPY .docker/cron/crontab /etc/jobscan/crontab
RUN chmod +x /usr/local/bin/jobscan-entrypoint

COPY --from=build --chown=app:app /app /app
RUN mkdir -p var && chown -R app:app var

ENV APP_ENV=prod
USER app

ENTRYPOINT ["jobscan-entrypoint"]
CMD ["php-fpm"]
