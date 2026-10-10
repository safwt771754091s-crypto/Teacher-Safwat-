#!/usr/bin/env bash
# Runs the demo: Laravel API on :12000 and the static web app on :12001.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
WEB="$ROOT/web"

export PATH="/usr/local/bin:$PATH"
PHP_BIN="$(command -v php || true)"
if [ -z "$PHP_BIN" ] && [ -x /usr/bin/php8.4 ]; then PHP_BIN=/usr/bin/php8.4; fi
if [ -z "$PHP_BIN" ]; then echo "PHP not found. Run scripts/bootstrap_env.sh first."; exit 1; fi

(sudo service mariadb start >/dev/null 2>&1 || true)
(sudo service redis-server start >/dev/null 2>&1 || true)

API_PUBLIC="${API_PUBLIC_URL:-http://127.0.0.1:12000}"
WEB_PUBLIC="${WEB_PUBLIC_URL:-http://127.0.0.1:12001}"
printf "window.API_BASE_URL = '%s/api/v1';\n" "$API_PUBLIC" > "$WEB/assets/config.js"

pkill -f "artisan serve --host=0.0.0.0 --port=12000" 2>/dev/null || true
pkill -f "php -S 0.0.0.0:12001" 2>/dev/null || true
sleep 1

cd "$BACKEND"
nohup "$PHP_BIN" artisan serve --host=0.0.0.0 --port=12000 >/tmp/api.log 2>&1 &
echo "API started (log: /tmp/api.log)"

nohup "$PHP_BIN" -S 0.0.0.0:12001 -t "$WEB" >/tmp/web.log 2>&1 &
echo "Web started (log: /tmp/web.log)"

sleep 2
echo "API:  http://127.0.0.1:12000/api/v1"
echo "Web:  http://127.0.0.1:12001/"
echo "Public API: $API_PUBLIC/api/v1"
echo "Public web: $WEB_PUBLIC/"
