# Development image: PHP 8.4 CLI (the version the spec targets) with Composer and the extensions the
# package and its tests need. Used through compose.yaml; not part of the distributed package.
FROM php:8.4-cli-alpine

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache git unzip \
    && install-php-extensions bcmath gd intl pcntl pcov pdo_mysql pdo_pgsql zip

ENV COMPOSER_HOME=/tmp/composer
