# syntax=docker/dockerfile:1

# Image web de production : nginx + l'écran React construit (même schéma que Coelbook : un build statique copié dans
# l'image finale). Contexte : la racine du dépôt. Aucun code PHP ici : `/api/` et `/health` sont relayés à php-fpm.
#
# Le développement n'utilise pas cette image : il monte `default.conf` dans nginx:alpine et lance Vite (docker-compose.yml).

# ── Écran React : build statique ────────────────────────────────────────────────────────────────────
FROM --platform=$BUILDPLATFORM node:24-alpine AS frontend

WORKDIR /src/frontend

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY frontend/ ./
RUN npm run build

# ── nginx ───────────────────────────────────────────────────────────────────────────────────────────
FROM nginx:alpine

COPY .docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY frontend/security-headers.conf /etc/nginx/jobscan/security-headers.conf
COPY --from=frontend /src/frontend/dist /usr/share/nginx/html
