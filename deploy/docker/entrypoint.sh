#!/bin/sh
# VVA container entrypoint – shared by web, reverb, queue and scheduler.
set -e
cd /app

if [ -z "$APP_KEY" ]; then
    echo "[vva] APP_KEY is empty – run ./vva.sh setup first." >&2
    exit 1
fi

# The storage volume may be fresh (first start) – make sure the tree exists.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[vva] Running database migrations…"
    tries=0
    until php artisan migrate --force --no-interaction; do
        tries=$((tries + 1))
        if [ "$tries" -ge 10 ]; then
            echo "[vva] Migrations failed 10 times, giving up." >&2
            exit 1
        fi
        echo "[vva] Database not ready yet, retrying in 5 s…"
        sleep 5
    done
fi

# Cache config, routes, events and views for speed (env comes from Docker).
php artisan optimize --no-interaction >/dev/null

exec "$@"
