#!/usr/bin/env bash
# Update to a newer release:  ./update.sh /path/to/new-release-dir-or-zip
# Copies new code over this folder's parent (never touching .env or Docker volumes), makes a backup first, rebuilds, migrates, restarts.
set -Eeuo pipefail
trap 'echo "ERROR at line $LINENO: $BASH_COMMAND" >&2; echo "The previous containers are still running unless the failure was after \"up -d\"." >&2' ERR
cd "$(dirname "${BASH_SOURCE[0]}")"
SRC="${1:-}"; [ -n "$SRC" ] || { echo "usage: $0 <new-release-dir-or-zip>" >&2; exit 2; }
ROOT="$(cd .. && pwd)"
TMP=""
if [ -f "$SRC" ]; then
  command -v unzip >/dev/null || { echo "unzip is required" >&2; exit 1; }
  TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
  unzip -q "$SRC" -d "$TMP"
  SRC="$TMP"; [ -d "$SRC/selfhost" ] || SRC="$(find "$TMP" -maxdepth 2 -type d -name selfhost -printf '%h\n' | head -1)"
fi
[ -d "$SRC/selfhost" ] && [ -d "$SRC/apps/web-overlay" ] || { echo "not a JARVIS release (selfhost/ and apps/web-overlay/ expected)" >&2; exit 1; }
[ -f .env ] || { echo ".env missing: is this an installed server?" >&2; exit 1; }
echo "==> backup before update"
./backup.sh "${JARVIS_BACKUP_DIR:-/var/backups/jarvis}"
echo "==> copy new files (keeps .env)"
rsync -a --exclude '.env' --exclude '.license-key.pending' --exclude 'apps/web/' --exclude '.git' "$SRC"/ "$ROOT"/
VER="$(sed -n 's/^## \[\{0,1\}\([0-9][0-9.]*\).*/\1/p' "$ROOT/CHANGELOG.md" | head -1)"
if grep -q '^JARVIS_VERSION=' .env; then sed -i "s#^JARVIS_VERSION=.*#JARVIS_VERSION=${VER:-local}#" .env; else echo "JARVIS_VERSION=${VER:-local}" >> .env; fi
DC=(docker compose --env-file .env -f docker-compose.yml)
echo "==> build"
"${DC[@]}" build
echo "==> migrate (maintenance mode on)"
"${DC[@]}" up -d postgres redis
"${DC[@]}" exec -T web php artisan down --retry=30 || true
"${DC[@]}" --profile setup run --rm provision
"${DC[@]}" --profile setup run --rm migrate
echo "==> restart"
"${DC[@]}" up -d
"${DC[@]}" exec -T web php artisan up || true
./healthcheck.sh
