#!/usr/bin/env bash
# One command for everything:  ./run-all.sh [/path/to/new-release.zip]
# Updates the code (if a zip is given), rebuilds, migrates, fixes permissions and the storage link, clears caches, restarts,
# then opens the main pages and prints the real error behind any 500.
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
DC=(docker compose --env-file .env -f docker-compose.yml)
if [ -n "${1:-}" ]; then
  echo "==> update from $1"; ./update.sh "$1"
else
  echo "==> rebuild + migrate (no zip given)"
  "${DC[@]}" build
  "${DC[@]}" up -d postgres redis
  "${DC[@]}" --profile setup run --rm provision
  "${DC[@]}" --profile setup run --rm migrate
  "${DC[@]}" up -d
fi
echo "==> permissions, storage link, caches"
"${DC[@]}" exec -T -u root web sh -c 'mkdir -p storage/app/public storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
  && rm -rf public/storage && ln -s /var/www/html/storage/app/public public/storage \
  && chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rw storage bootstrap/cache' || echo "(permission step skipped)"
"${DC[@]}" exec -T web php artisan optimize:clear || true
"${DC[@]}" restart web horizon scheduler nginx
sleep 5
echo "==> health"; ./healthcheck.sh || true
echo "==> page check (the real error behind any 500 is shown here)"
./diag.sh || true
echo "==> done. If a page above shows 500, send me those lines."
