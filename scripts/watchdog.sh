#!/usr/bin/env bash
# Self-healing watchdog for the demo sandbox.
#
# The Agent Canvas sandbox is periodically rebuilt from scratch, which removes
# PHP, MariaDB, Redis and the OCR toolchain and drops the database. When that
# happens the API/web ports return 000. This loop detects the loss (or a dead
# service) and re-runs bootstrap_env.sh to restore everything, then keeps
# serving. Safe to run detached:  nohup bash scripts/watchdog.sh &>/tmp/watchdog.log &
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PATH="/usr/local/bin:$PATH"
INTERVAL="${WATCHDOG_INTERVAL:-60}"

# Never touch the system while apt/dpkg holds the lock (a reset install may be
# mid-flight); the next tick will handle it.
apt_busy() {
    pgrep -x apt-get >/dev/null 2>&1 || pgrep -x dpkg >/dev/null 2>&1 || pgrep -x unattended-upgrade >/dev/null 2>&1
}

ports_down() {
    local api web
    api=$(curl -s -o /dev/null -w '%{http_code}' --max-time 4 http://127.0.0.1:12000/api/v1/config 2>/dev/null || echo 000)
    web=$(curl -s -o /dev/null -w '%{http_code}' --max-time 4 http://127.0.0.1:12001/ 2>/dev/null || echo 000)
    [ "$api" != "200" ] || [ "$web" != "200" ]
}

echo "[watchdog] started $(date -Is); interval=${INTERVAL}s"
tick=0
while true; do
    if apt_busy; then
        sleep "$INTERVAL"
        continue
    fi

    # Refresh the workspace DB snapshot every ~10 minutes so a later reset
    # restores the latest imported content instead of an older dump.
    tick=$((tick + 1))
    if [ $((tick % 10)) -eq 0 ]; then
        bash "$ROOT/scripts/db_snapshot.sh" save >>/tmp/db_snapshot.log 2>&1 || true
    fi

    # Missing runtimes OR dead services both warrant a bootstrap.
    if ! command -v php >/dev/null 2>&1 && [ ! -x /usr/bin/php8.4 ]; then
        echo "[watchdog] $(date -Is) php missing -> bootstrapping"
        bash "$ROOT/scripts/bootstrap_env.sh" >>/tmp/bootstrap.log 2>&1
    elif ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
        echo "[watchdog] $(date -Is) mariadb missing -> bootstrapping"
        bash "$ROOT/scripts/bootstrap_env.sh" >>/tmp/bootstrap.log 2>&1
    elif ports_down; then
        echo "[watchdog] $(date -Is) services down -> restarting demo"
        sudo service mariadb start >/dev/null 2>&1 || true
        sudo service redis-server start >/dev/null 2>&1 || true
        # A reset can leave MariaDB running but empty; bootstrap restores the
        # snapshot in that case (run_demo alone would leave login broken).
        tables=$(sudo mariadb -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME:-teacher_safwat}';" 2>/dev/null || echo 0)
        if [ "${tables:-0}" -lt 10 ]; then
            bash "$ROOT/scripts/bootstrap_env.sh" >>/tmp/bootstrap.log 2>&1
        else
            bash "$ROOT/scripts/run_demo.sh" >>/tmp/bootstrap.log 2>&1
        fi
    fi

    sleep "$INTERVAL"
done
