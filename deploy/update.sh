#!/usr/bin/env bash
# Apply a new phase ZIP on an installed server.
#   1. unzip the new jarvis-one-phase-NN.zip over /opt/jarvis-one (do NOT delete apps/web or .env)
#   2. sudo /opt/jarvis-one/deploy/update.sh
set -Eeuo pipefail
trap 'echo "ERROR at line $LINENO: $BASH_COMMAND" >&2' ERR
[ "$(id -u)" -eq 0 ] || { echo "run as root"; exit 1; }
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
set -a; . ./.env; set +a
chown -R jarvis:jarvis "$ROOT/apps/web-overlay" "$ROOT/deploy" "$ROOT/scripts" "$ROOT/infra" "$ROOT/apps/ai-engine" 2>/dev/null || true

echo "==> engine + containers"
sudo -u jarvis docker compose -f infra/docker-compose.yml --env-file .env up -d --build
echo "==> overlay -> apps/web"
sudo -u jarvis cp -R apps/web-overlay/. apps/web/
cd apps/web
sudo -u jarvis -H composer install --optimize-autoloader --no-interaction
sudo -u jarvis -H npm install --no-audit --no-fund
sudo -u jarvis -H npm run build
echo "==> maintenance mode, migrate, caches"
sudo -u jarvis php artisan down --retry=30 || true
trap 'sudo -u jarvis php artisan up || true' EXIT
sudo -u jarvis -H env DB_USERNAME=jarvis_owner DB_PASSWORD="$DB_OWNER_PASSWORD" php artisan migrate --force
sudo -u jarvis -H env DB_USERNAME=jarvis_owner DB_PASSWORD="$DB_OWNER_PASSWORD" php artisan db:seed --class=PlatformSeeder --force || true
sudo -u jarvis php artisan tenancy:check
sudo -u jarvis php artisan roles:sync
sudo -u jarvis php artisan config:cache
sudo -u jarvis php artisan view:cache
sudo -u jarvis php artisan route:cache || true
chown -R jarvis:www-data storage bootstrap/cache
systemctl restart php8.3-fpm
systemctl restart jarvis-horizon || true
sudo -u jarvis php artisan up
trap - EXIT
"$ROOT/deploy/healthcheck.sh"
