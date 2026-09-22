FROM dunglas/frankenphp:1-php8.3

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    @composer \
    apcu \
    intl \
    opcache \
    pdo_pgsql \
    redis \
    zip


RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY docker/php/Caddyfile /etc/frankenphp/Caddyfile