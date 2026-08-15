FROM composer:2.10.2 AS composer
FROM php:8.5.9-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev libpq-dev libzip-dev nginx unzip \
    && docker-php-ext-install intl pcntl pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/nginx.conf /etc/nginx/nginx.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN echo "error_log = /tmp/php-fpm.log" >> /usr/local/etc/php-fpm.conf \
    && echo "pid = /tmp/php-fpm.pid" >> /usr/local/etc/php-fpm.conf \
    && sed -i 's/^listen = .*/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/www.conf \
    && echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf \
    && echo "catch_workers_output = yes" >> /usr/local/etc/php-fpm.d/www.conf

WORKDIR /app
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
