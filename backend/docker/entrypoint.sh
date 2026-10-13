#!/usr/bin/env bash
set -e

cd /app

# Create the SQLite database file on first boot (Render free tier, no MySQL).
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    touch "${DB_DATABASE:-/app/database/database.sqlite}"
fi

# App key: use the provided one, otherwise generate a persistent-enough key.
if [ -z "${APP_KEY:-}" ]; then
    export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    echo "APP_KEY not set; generated an ephemeral key (sessions reset on restart)."
fi

php artisan config:clear >/dev/null 2>&1 || true

# Migrate + seed. Seeding is idempotent, so it is safe to run on every boot and
# guarantees a fresh database is usable even after the free-tier disk is wiped.
php artisan migrate --force
php artisan db:seed --force

# Cache config/routes for production.
php artisan config:cache
php artisan route:cache || true

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
