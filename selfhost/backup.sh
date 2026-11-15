#!/usr/bin/env bash
# Backup: PostgreSQL dump (custom format) + .env (holds the key vault key) + the app storage volume. Keeps the newest 14 of each.
#   ./backup.sh [/var/backups/jarvis]        cron:  15 2 * * * cd /opt/jarvis/selfhost && ./backup.sh
# Copy the folder OFF this server too. Restore with ./restore.sh.
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
DEST="${1:-/var/backups/jarvis}"
[ -f .env ] || { echo ".env missing" >&2; exit 1; }
set -a; . ./.env; set +a
DC=(docker compose --env-file .env -f docker-compose.yml)
umask 077
mkdir -p "$DEST"
STAMP="$(date +%Y%m%d-%H%M%S)"
"${DC[@]}" exec -T postgres pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc > "$DEST/db-$STAMP.dump"
if [ ! -s "$DEST/db-$STAMP.dump" ]; then echo "empty dump!" >&2; rm -f "$DEST/db-$STAMP.dump"; exit 1; fi
cp .env "$DEST/env-$STAMP"
"${DC[@]}" exec -T web tar -C /var/www/html/storage -cf - app 2>/dev/null | gzip > "$DEST/storage-$STAMP.tgz" || true
for p in db-*.dump env-* storage-*.tgz; do ls -1t "$DEST"/$p 2>/dev/null | tail -n +15 | xargs -r rm -f; done
echo "Backup written to $DEST  (stamp $STAMP). Copy it OFF this server too."
