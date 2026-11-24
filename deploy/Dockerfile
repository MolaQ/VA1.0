# syntax=docker/dockerfile:1
#
# VVA production image: FrankenPHP (Caddy + PHP 8.4) serving Laravel.
# The same image runs the web server, Reverb, the queue worker and the scheduler.
# Build from the repository root:  docker compose -f deploy/compose.yml build

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ---------------------------------------------------------------- PHP base
FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm AS base

RUN install-php-extensions pdo_mysql intl zip bcmath pcntl sockets opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY deploy/docker/php.ini $PHP_INI_DIR/conf.d/zz-vva.ini

WORKDIR /app

# ---------------------------------------------------------------- Composer deps
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi

# ---------------------------------------------------------------- Front-end build
FROM node:${NODE_VERSION}-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .
# Tailwind scans Blade views shipped inside vendor/ (pagination).
COPY --from=vendor /app/vendor /app/vendor

# Only the public Reverb key is baked into the bundle. Host, port and scheme are
# taken from the page address at runtime, so one image works on any domain / IP.
ARG VITE_APP_NAME=VVA
ARG VITE_REVERB_APP_KEY
ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
RUN npm run build

# ---------------------------------------------------------------- Final image
FROM base AS app

ENV SERVER_NAME=:80 \
    APP_ENV=production \
    APP_DEBUG=false

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY deploy/docker/Caddyfile /etc/frankenphp/Caddyfile
COPY deploy/docker/entrypoint.sh /usr/local/bin/vva-entrypoint

RUN chmod +x /usr/local/bin/vva-entrypoint \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && ln -sfn /app/storage/app/public /app/public/storage \
    # Run as www-data, but still allow binding ports 80/443.
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

USER www-data

ENTRYPOINT ["vva-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
