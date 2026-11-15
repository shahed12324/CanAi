#!/usr/bin/env bash
# Nightly-safe backup: PostgreSQL dump (custom format) + the secrets file. Keeps the newest 14 dumps.
#   sudo ./deploy/backup.sh [/var/backups/jarvis]      cron example:  15 2 * * * root /opt/jarvis-one/deploy/backup.sh
# Restore:  docker exec -i jarvis-postgres-1 pg_restore -U jarvis -d jarvis --clean --if-exists < dump_file
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${1:-/var/backups/jarvis}"
set -a; . "$ROOT/.env"; set +a
umask 077
mkdir -p "$DEST"
STAMP="$(date +%Y%m%d-%H%M%S)"
docker exec jarvis-postgres-1 pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc > "$DEST/db-$STAMP.dump"
[ -s "$DEST/db-$STAMP.dump" ] || { echo "empty dump!" >&2; rm -f "$DEST/db-$STAMP.dump"; exit 1; }
cp "$ROOT/.env" "$DEST/env-$STAMP"      # contains AI_VAULT_KEY_1: without it stored provider keys are unreadable
ls -1t "$DEST"/db-*.dump 2>/dev/null | tail -n +15 | xargs -r rm -f
ls -1t "$DEST"/env-* 2>/dev/null | tail -n +15 | xargs -r rm -f
echo "Backup written to $DEST (copy it OFF this server too)."
