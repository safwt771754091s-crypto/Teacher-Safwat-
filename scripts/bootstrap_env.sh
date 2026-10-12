#!/usr/bin/env bash
# Bootstraps a fresh sandbox: installs PHP/MariaDB/Redis/Composer, creates the
# database, and (with FRESH=1) seeds the curriculum. Then starts the demo.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"

DB_NAME="${DB_NAME:-teacher_safwat}"
DB_USER="${DB_USER:-ts}"
DB_PASS="${DB_PASS:-ts_secret}"

export DEBIAN_FRONTEND=noninteractive
export PATH="/usr/local/bin:$PATH"

need_apt=0
command -v php >/dev/null 2>&1 || [ -x /usr/bin/php8.4 ] || need_apt=1
command -v mariadbd >/dev/null 2>&1 || command -v mysqld >/dev/null 2>&1 || need_apt=1
# OCR toolchain is required to read scanned Yemeni textbooks (no text layer).
command -v tesseract >/dev/null 2>&1 || need_apt=1
command -v pdftoppm >/dev/null 2>&1 || need_apt=1

if [ "$need_apt" = "1" ]; then
    echo "Installing system packages..."
    sudo apt-get update -qq
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        php8.4-cli php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath \
        php8.4-gd php8.4-intl php8.4-mysql php8.4-redis php8.4-sqlite3 \
        mariadb-server redis-server unzip curl git \
        tesseract-ocr tesseract-ocr-ara poppler-utils
    sudo ln -sf /usr/bin/php8.4 /usr/local/bin/php
fi

[ -x /usr/bin/php8.4 ] && sudo ln -sf /usr/bin/php8.4 /usr/local/bin/php

command -v composer >/dev/null 2>&1 || {
    echo "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
}

sudo service mariadb start >/dev/null 2>&1 || true
sudo service redis-server start >/dev/null 2>&1 || true
sleep 3

sudo mariadb -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;"

cd "$BACKEND"
[ -f vendor/autoload.php ] || composer install --no-interaction

TABLES=$(sudo mariadb -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';")
if [ "${FRESH:-0}" = "1" ]; then
    echo "Seeding database (FRESH)..."
    php artisan migrate:fresh --seed --force
    bash "$ROOT/scripts/db_snapshot.sh" save || true
elif [ "${TABLES:-0}" -lt 10 ]; then
    # The sandbox wipe recreated an empty MariaDB. Prefer the last workspace
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
