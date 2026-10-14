#!/usr/bin/env bash
# Persist the demo database across sandbox rebuilds (PostgreSQL edition).
#
# The Agent Canvas sandbox is wiped periodically: PostgreSQL and its data dir are
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
DB_USER="${DB_USER:-school}"
DB_PASS="${DB_PASS:-school_secret}"
SNAP="$ROOT/backend/storage/app/db_snapshot.sql"

# 1 when the database exists and already holds the app tables.
db_has_tables() {
    local n
    n=$(PGPASSWORD="$DB_PASS" psql -h 127.0.0.1 -U "$DB_USER" -d "$DB_NAME" -tAc \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public';" 2>/dev/null || echo 0)
    [ "${n:-0}" -ge 10 ]
}

action="${1:-status}"
case "$action" in
    save)
        db_has_tables || { echo "no tables to snapshot"; exit 1; }
        tmp="${SNAP}.tmp"
        PGPASSWORD="$DB_PASS" pg_dump -h 127.0.0.1 -U "$DB_USER" -d "$DB_NAME" \
            --no-owner --no-privileges --clean --if-exists > "$tmp" \
            || { echo "dump failed"; rm -f "$tmp"; exit 1; }
        mv "$tmp" "$SNAP"
        echo "snapshot saved: $(du -h "$SNAP" | cut -f1) $(date -Is)"
        ;;
    restore)
        [ -s "$SNAP" ] || { echo "no snapshot at $SNAP"; exit 1; }
        if db_has_tables; then
            echo "database already populated; skipping restore"
            exit 0
        fi
        sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 \
            || sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}' SUPERUSER;"
        sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1 \
            || sudo -u postgres psql -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};"
        PGPASSWORD="$DB_PASS" psql -h 127.0.0.1 -U "$DB_USER" -d "$DB_NAME" < "$SNAP" \
            || { echo "restore failed"; exit 1; }
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
