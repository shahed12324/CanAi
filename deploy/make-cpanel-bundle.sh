#!/usr/bin/env bash
# Packs the BUILT Laravel app (vendor/ and public/build included) so a cPanel host without Composer/Node can run it.
# Run on the VPS after install-vps.sh:   ./deploy/make-cpanel-bundle.sh   ->  /tmp/jarvis-cpanel.zip
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB="$ROOT/apps/web"
[ -f "$WEB/artisan" ] && [ -d "$WEB/vendor" ] && [ -f "$WEB/public/build/manifest.json" ] || { echo "apps/web is not built yet (run install-vps.sh first)." >&2; exit 1; }
OUT="${1:-/tmp/jarvis-cpanel.zip}"
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT
rsync -a --delete \
  --exclude '.env' --exclude '.git' --exclude 'node_modules' --exclude 'tests' --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/data/*' --exclude 'storage/framework/sessions/*' --exclude 'storage/framework/views/*' \
  --exclude 'bootstrap/cache/*.php' --exclude 'public/hot' \
  "$WEB"/ "$STAGE/jarvis/"
mkdir -p "$STAGE/jarvis/bootstrap/cache" "$STAGE/jarvis/storage/logs" "$STAGE/jarvis/storage/framework/"{cache/data,sessions,views}
cp "$ROOT/deploy/cpanel/env.cpanel.example" "$STAGE/jarvis/.env.example"
cp "$ROOT/deploy/cpanel/public.htaccess" "$STAGE/jarvis/public/.htaccess"
cp "$ROOT/deploy/cpanel/cpanel-precheck.php" "$STAGE/jarvis/public/cpanel-precheck.php"
(cd "$STAGE" && rm -f "$OUT" && zip -qr "$OUT" jarvis)
echo "Created $OUT ($(du -h "$OUT" | cut -f1)). Follow deploy/CPANEL.md."
