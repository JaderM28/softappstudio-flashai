#!/bin/sh
set -e

# Directories nginx and the app write to. The image is otherwise read-only, and
# Render's disk is ephemeral, which is exactly why pictures and audio go to
# Supabase Storage rather than here.
mkdir -p /tmp/nginx-client /tmp/nginx-proxy /tmp/nginx-fastcgi /tmp/nginx-uwsgi /tmp/nginx-scgi
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

# Caching config reads .env once at boot instead of on every request. It has to
# happen here rather than at build time: the environment does not exist yet
# while the image is being built.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# The queue lives in the database, so its table has to exist before the worker
# supervisord is about to start looks for it.
php artisan migrate --force

exec supervisord -c /etc/supervisord.conf
