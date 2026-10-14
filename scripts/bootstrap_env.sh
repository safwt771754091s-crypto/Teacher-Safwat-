#!/usr/bin/env bash
# Bootstraps a fresh sandbox: installs PHP/PostgreSQL/Redis/Composer, creates the
# database, and (with FRESH=1) seeds the curriculum. Then starts the demo.
#
# PostgreSQL is used locally so the development stack matches production, which
# runs on Supabase PostgreSQL. To point the app at Supabase instead, set
# DB_CONNECTION=pgsql + DB_URL (or DB_HOST/DB_USERNAME/DB_PASSWORD) in backend/.env.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"

DB_NAME="${DB_NAME:-teacher_safwat}"
DB_USER="${DB_USER:-school}"
DB_PASS="${DB_PASS:-school_secret}"

export DEBIAN_FRONTEND=noninteractive
export PATH="/usr/local/bin:$PATH"

need_apt=0
command -v php >/dev/null 2>&1 || [ -x /usr/bin/php8.4 ] || need_apt=1
command -v pg_ctlcluster >/dev/null 2>&1 || need_apt=1
# OCR toolchain is required to read scanned Yemeni textbooks (no text layer).
command -v tesseract >/dev/null 2>&1 || need_apt=1
command -v pdftoppm >/dev/null 2>&1 || need_apt=1

if [ "$need_apt" = "1" ]; then
    echo "Installing system packages..."
    sudo apt-get update -qq
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        php8.4-cli php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath \
        php8.4-gd php8.4-intl php8.4-pgsql php8.4-redis php8.4-sqlite3 \
        postgresql postgresql-client redis-server unzip curl git \
        tesseract-ocr tesseract-ocr-ara poppler-utils
    sudo ln -sf /usr/bin/php8.4 /usr/local/bin/php
fi

[ -x /usr/bin/php8.4 ] && sudo ln -sf /usr/bin/php8.4 /usr/local/bin/php

command -v composer >/dev/null 2>&1 || {
    echo "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
}

sudo service postgresql start >/dev/null 2>&1 || true
sudo service redis-server start >/dev/null 2>&1 || true
sleep 3

sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 \
    || sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}' SUPERUSER;"
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1 \
    || sudo -u postgres psql -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};"

cd "$BACKEND"
[ -f vendor/autoload.php ] || composer install --no-interaction

TABLES=$(PGPASSWORD="$DB_PASS" psql -h 127.0.0.1 -U "$DB_USER" -d "$DB_NAME" -tAc \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public';" 2>/dev/null || echo 0)
if [ "${FRESH:-0}" = "1" ]; then
    echo "Seeding database (FRESH)..."
    php artisan migrate:fresh --seed --force
    bash "$ROOT/scripts/db_snapshot.sh" save || true
elif [ "${TABLES:-0}" -lt 10 ]; then
    # The sandbox wipe recreated an empty PostgreSQL. Prefer the last workspace
    # snapshot (keeps imported textbooks + OCR pages) over a bare reseed.
    if [ -s "$BACKEND/storage/app/db_snapshot.sql" ]; then
        echo "Restoring database from workspace snapshot..."
        bash "$ROOT/scripts/db_snapshot.sh" restore || php artisan migrate:fresh --seed --force
    else
        echo "Seeding database (no snapshot)..."
        php artisan migrate:fresh --seed --force
    fi
fi

php artisan config:clear >/dev/null 2>&1 || true

bash "$ROOT/scripts/run_demo.sh"

# Keep the demo alive across future sandbox resets.
if ! pgrep -f "scripts/watchdog.sh" >/dev/null 2>&1; then
    nohup bash "$ROOT/scripts/watchdog.sh" >/tmp/watchdog.log 2>&1 &
    echo "Watchdog started (log: /tmp/watchdog.log)"
fi
