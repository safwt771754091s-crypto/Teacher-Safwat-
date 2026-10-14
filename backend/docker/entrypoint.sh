#!/usr/bin/env bash
set -e

cd /app

# SQLite fallback: create the database file on first boot (local/no-services).
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    touch "${DB_DATABASE:-/app/database/database.sqlite}"
fi

# App key: use the provided one, otherwise generate a persistent-enough key.
if [ -z "${APP_KEY:-}" ]; then
    export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    echo "APP_KEY not set; generated an ephemeral key (sessions reset on restart)."
fi

php artisan config:clear >/dev/null 2>&1 || true

# Migrate + seed. Seeding is idempotent and, in production, skips the demo
# accounts (see UsersAndGamesSeeder) so no default password is ever created.
php artisan migrate --force
php artisan db:seed --force

# Create the owner from environment secrets when provided. This is how the
# first production owner is created without shipping credentials in the image.
if [ -n "${OWNER_EMAIL:-}" ] && [ -n "${OWNER_PASSWORD:-}" ]; then
    php artisan platform:bootstrap-owner || echo "owner bootstrap skipped"
fi

# Cache config/routes for production.
php artisan config:cache
php artisan route:cache || true

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
