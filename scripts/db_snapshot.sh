#!/usr/bin/env bash
# Persist the demo database across sandbox rebuilds.
#
# The Agent Canvas sandbox is wiped periodically: MariaDB and its data dir are
# reinstalled empty, but /workspace persists. We therefore keep a logical dump
# (schema + data) of the seeded/imported database in /workspace, and restore it
# automatically after a reset so imported textbooks and OCR pages are not lost.
#
#   bash scripts/db_snapshot.sh save      # dump the live DB -> workspace snapshot
#   bash scripts/db_snapshot.sh restore   # load the snapshot into an empty DB
#   bash scripts/db_snapshot.sh status    # show snapshot age/size
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export PATH="/usr/local/bin:$PATH"

DB_NAME="${DB_NAME:-teacher_safwat}"
DB_USER="${DB_USER:-ts}"
DB_PASS="${DB_PASS:-ts_secret}"
SNAP="$ROOT/backend/storage/app/db_snapshot.sql"

mariadb_cli() { sudo mariadb "$@"; }

# 1 when the database exists and already holds the app tables.
db_has_tables() {
    local n
    n=$(mariadb_cli -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" 2>/dev/null)
    [ "${n:-0}" -ge 10 ]
}

action="${1:-status}"
case "$action" in
    save)
        db_has_tables || { echo "no tables to snapshot"; exit 1; }
        dump="$(command -v mariadb-dump || command -v mysqldump)"
        [ -n "$dump" ] || { echo "no dump binary found"; exit 1; }
        tmp="${SNAP}.tmp"
        sudo "$dump" --single-transaction --quick --skip-comments \
            "$DB_NAME" > "$tmp" || { echo "dump failed"; rm -f "$tmp"; exit 1; }
        mv "$tmp" "$SNAP"
        echo "snapshot saved: $(du -h "$SNAP" | cut -f1) $(date -Is)"
        ;;
    restore)
        [ -s "$SNAP" ] || { echo "no snapshot at $SNAP"; exit 1; }
        if db_has_tables; then
            echo "database already populated; skipping restore"
            exit 0
        fi
        mariadb_cli -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
            GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'127.0.0.1'; FLUSH PRIVILEGES;"
        mariadb_cli "$DB_NAME" < "$SNAP" || { echo "restore failed"; exit 1; }
        echo "snapshot restored: $(date -Is)"
        ;;
    *)
        if [ -s "$SNAP" ]; then
            echo "snapshot: $(du -h "$SNAP" | cut -f1), modified $(date -r "$SNAP" -Is 2>/dev/null)"
        else
            echo "snapshot: none"
        fi
        ;;
esac
