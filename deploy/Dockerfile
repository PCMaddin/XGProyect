# syntax=docker/dockerfile:1.7
#
# The game: php-fpm 8.4 with OPcache and the code, run as www-data.
# Built by deploy.sh; behind a proxy that re-signs TLS, hand its CA in as the
# build secret "extra_ca" (deploy.sh does it when EXTRA_CA is set).

FROM php:8.4-fpm-bookworm AS php

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev libicu-dev \
        libfcgi-bin mariadb-client unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" bcmath gd intl mysqli opcache pcntl pdo_mysql zip; \
    rm -rf /var/lib/apt/lists/*

RUN --mount=type=secret,id=extra_ca,required=false \
    set -eux; \
    if [ -f /run/secrets/extra_ca ]; then cp /run/secrets/extra_ca /usr/local/share/ca-certificates/extra.crt; update-ca-certificates; fi; \
    curl -fsSL -o /tmp/redis.tgz https://pecl.php.net/get/redis-6.2.0.tgz; \
    pecl install /tmp/redis.tgz; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/redis.tgz /tmp/pear /usr/local/share/ca-certificates/extra.crt; \
    update-ca-certificates --fresh >/dev/null

FROM php AS build

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
WORKDIR /srv/app
COPY composer.json composer.lock ./
RUN --mount=type=secret,id=extra_ca,required=false \
    set -eux; \
    if [ -f /run/secrets/extra_ca ]; then export COMPOSER_CAFILE=/run/secrets/extra_ca; fi; \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY . .
COPY deploy/php/xgp-db-config.php config/xgp-db-config.php
# compiled once here, never in the shared storage: two versions run side by
# side while a new one rolls out
ENV VIEW_COMPILED_PATH=/srv/app/bootstrap/views
RUN set -eux; \
    mkdir -p bootstrap/views storage/app/backups storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction; \
    php artisan package:discover --no-interaction; \
    php artisan view:cache --no-interaction; \
    php artisan route:cache --no-interaction; \
    php artisan event:cache --no-interaction; \
    rm -f bootstrap/cache/config.php

FROM php AS app

COPY deploy/php/php.ini "$PHP_INI_DIR/conf.d/zz-xgproyect.ini"
COPY deploy/php/www.conf /usr/local/etc/php-fpm.d/zz-xgproyect.conf
COPY deploy/php/selftest.conf /usr/local/etc/php-fpm-selftest.conf
COPY deploy/entrypoint.sh /usr/local/bin/xgp-entrypoint
COPY --from=build --chown=www-data:www-data /srv/app /srv/app
# where the static files for the web server go (a volume takes this owner)
RUN install -d -o www-data -g www-data /srv/public

ENV VIEW_COMPILED_PATH=/srv/app/bootstrap/views \
    PHP_FPM_CHILDREN=16
WORKDIR /srv/app
USER www-data

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong

ENTRYPOINT ["xgp-entrypoint"]
CMD ["php-fpm"]
