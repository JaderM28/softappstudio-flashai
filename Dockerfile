# syntax=docker/dockerfile:1

# ---------------------------------------------------------------- assets ---
# Vite runs once, here, and the built CSS and JS are copied into the final
# image. Node is not wanted at runtime.

FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install --ignore-scripts

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ------------------------------------------------------------ dependencies ---

FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# No dev packages and no scripts: artisan is not fully wired up until the
# application code arrives in the next stage.
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# ----------------------------------------------------------------- runtime ---

FROM php:8.4-fpm-alpine

# ffmpeg is the reason the audio is stored as MP3 rather than WAV — roughly
# 20 KB a clip against 230 KB, which on Supabase's 1 GB free bucket is the
# difference between about nineteen months of daily use and about four years.
# postgresql-dev builds pdo_pgsql for Supabase; the rest is nginx and the
# process manager that runs it beside the queue worker.
RUN apk add --no-cache \
        nginx \
        supervisor \
        ffmpeg \
        postgresql-libs \
        icu-libs \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev \
        icu-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        intl \
        opcache \
    && apk del .build-deps

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

# Replaces the image's default pool rather than adding to it: without this,
# php-fpm allows five children at a 256M limit each inside a 512 MB container,
# and Render answers an OOM by killing the container rather than the process.
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Composer's scripts were skipped while the application code was still missing.
# They are run here, where every PHP extension the app needs is present, with
# the binary borrowed for the one step and then dropped rather than left in the
# runtime image.
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && rm -f /usr/local/bin/composer \
    && chown -R www-data:www-data storage bootstrap/cache

# Render routes to whatever this listens on. nginx is configured for the same.
EXPOSE 8080

ENTRYPOINT ["entrypoint"]
