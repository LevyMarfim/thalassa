# syntax=docker/dockerfile:1
# Bleeding edge: PHP 8.5 + FrankenPHP 1 on Debian bookworm.
ARG PHP_VERSION=8.5
ARG FRANKENPHP_VERSION=1
ARG COMPOSER_VERSION=2

FROM composer:${COMPOSER_VERSION} AS composer

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-bookworm AS app

WORKDIR /app

# pdo_pgsql: Postgres / intl: pt_BR + en_US money/date formatting
# opcache + apcu: prod cache / pcntl: messenger:consume / zip: composer
RUN install-php-extensions \
    apcu \
    intl \
    opcache \
    pcntl \
    pdo_pgsql \
    zip

ENV APP_ENV=prod \
    FRANKENPHP_CONFIG="worker ./public/index.php" \
    FRANKENPHP_RESET_KERNEL=1 \
    SERVER_NAME=:80

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-cache --no-dev --no-interaction --no-scripts --prefer-dist

COPY . .
RUN rm -f .env.dev .env.test \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console importmap:install --no-interaction \
    && php bin/console assets:install public --no-interaction

# Production cache is warmed at container start (see docker-entrypoint.sh) so
# the real APP_SECRET / DATABASE_URL from the runtime environment are used,
# never build-time placeholders.
