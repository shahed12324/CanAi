#!/usr/bin/env bash
# Restore a database dump made by backup.sh. DESTRUCTIVE: replaces the current database contents.
#   ./restore.sh /var/backups/jarvis/db-20260101-021500.dump [--yes]
# Put the matching env-<stamp> back as .env FIRST if this is a new server (it holds AI_VAULT_KEY_1 and all passwords).
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
DUMP="${1:-}"; [ -s "$DUMP" ] || { echo "usage: $0 <dump-file> [--yes]" >&2; exit 2; }
if [ "${2:-}" != "--yes" ]; then
  read -r -p "This REPLACES the current database. Type RESTORE to continue: " a; [ "$a" = RESTORE ] || { echo aborted; exit 1; }
fi
set -a; . ./.env; set +a
DC=(docker compose --env-file .env -f docker-compose.yml)
"${DC[@]}" up -d postgres redis
"${DC[@]}" stop web horizon scheduler nginx 2>/dev/null || true
"${DC[@]}" exec -T postgres pg_restore -U "$DB_USERNAME" -d "$DB_DATABASE" --clean --if-exists --no-owner < "$DUMP"
"${DC[@]}" --profile setup run --rm provision     # re-assert role passwords/grants after restore
"${DC[@]}" up -d
echo "Restore finished. Run ./healthcheck.sh"
