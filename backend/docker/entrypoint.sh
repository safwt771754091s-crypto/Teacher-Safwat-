#!/usr/bin/env bash
set -e

cd /app

# Database target -----------------------------------------------------------
# A managed database is selected with DB_URL (a full connection string such as
# a Supabase session-pooler URL); the discrete DB_HOST/DB_USERNAME/... vars are
# the alternative. When DB_CONNECTION names a server driver but no real target
# is set, the default host (127.0.0.1) points inside this container and every
# query fails - so fall back to SQLite and say so loudly instead of crash-looping.
is_local_host() {
    case "${1:-}" in
        ""|127.0.0.1|localhost|::1) return 0 ;;
        *) return 1 ;;
    esac
}

if [ "${DB_CONNECTION:-sqlite}" != "sqlite" ]; then
    if [ -z "${DB_URL:-}" ] && is_local_host "${DB_HOST:-}"; then
        echo "WARNING: DB_CONNECTION=${DB_CONNECTION} but no DB_URL/DB_HOST is set; using SQLite." >&2
        echo "         Set DB_URL (e.g. the Supabase session-pooler string) for a persistent database." >&2
        export DB_CONNECTION=sqlite
        export DB_DATABASE=/app/database/database.sqlite
    fi
fi

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
#
# A configured-but-unreachable database is a real misconfiguration (e.g. a wrong
# DB_URL). Report it and keep serving so the health check and operator tooling
# stay reachable, instead of entering a crash loop.
if ! php artisan migrate --force; then
    echo "ERROR: migrate failed - check DB_URL / DB_* settings. Starting without migrations." >&2
else
    php artisan db:seed --force || echo "WARNING: seeding failed; continuing." >&2
fi

# Attach each book's source PDF from the bundled manifests so the in-app page
# viewer can render the real textbook pages. The seeder creates the catalogue
# with firstOrCreate (which never updates existing rows), so on an
# already-seeded database the URLs must be backfilled here. The command only
# touches rows whose source_url is still null, so it is safe to re-run.
for manifest in database/data/yemen_books_all.json database/data/yemen_books_aden.json database/data/yemen_books.json; do
    if [ -f "$manifest" ]; then
        php artisan textbooks:backfill-sources "$manifest" || echo "WARNING: backfill from $manifest failed; continuing." >&2
    fi
done

# Create the owner from environment secrets when provided. This is how the
# first production owner is created without shipping credentials in the image.
if [ -n "${OWNER_EMAIL:-}" ] && [ -n "${OWNER_PASSWORD:-}" ]; then
    php artisan platform:bootstrap-owner || echo "owner bootstrap skipped"
fi

# Cache config/routes for production.
php artisan config:cache
php artisan route:cache || true

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
