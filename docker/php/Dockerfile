FROM composer:2.10.2 AS composer
FROM php:8.5.9-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install intl pcntl pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
