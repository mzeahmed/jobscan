FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git unzip zip curl \
    libzip-dev libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install zip xml opcache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY app /app

RUN APP_ENV=prod composer install --no-dev --optimize-autoloader

RUN mkdir -p var && chmod -R 775 var

RUN APP_ENV=prod php bin/console asset-map:compile
