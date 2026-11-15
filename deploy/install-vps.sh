#!/usr/bin/env bash
# JARVIS ONE - one-command installer for a fresh Ubuntu 22.04 / 24.04 VPS (run as root).
#
#   sudo ./deploy/install-vps.sh --domain jarvis.example.com --email you@example.com
#   sudo ./deploy/install-vps.sh                      # no domain: plain HTTP on the server IP (testing)
#
# What it builds:  nginx + PHP-FPM (Laravel, native)  |  Docker: PostgreSQL 18 + Redis + AI engine (Python 3.13)
# Re-running is safe: finished steps are skipped, secrets are never regenerated, apps/web is never overwritten.
# Everything is logged to /var/log/jarvis-install.log. Needs outbound internet (packagist, npm, docker hub, apt).
set -Eeuo pipefail

DOMAIN=""; EMAIL=""; TARGET=/opt/jarvis-one; PRODUCTION_DEPS=0
while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift 2 ;;
    --email) EMAIL="${2:-}"; shift 2 ;;
    --target) TARGET="${2:-}"; shift 2 ;;
    --no-dev) PRODUCTION_DEPS=1; shift ;;     # skip Pest/Larastan/Pint (you cannot run tests on the server then)
    -h|--help) sed -n 2,10p "$0"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

LOG=/var/log/jarvis-install.log
exec > >(tee -a "$LOG") 2>&1
step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mERROR: %s\033[0m\nSee %s\n' "$*" "$LOG" >&2; exit 1; }
trap 'fail "step failed at line $LINENO (command: $BASH_COMMAND)"' ERR

[ "$(id -u)" -eq 0 ] || fail "run as root (sudo)."
. /etc/os-release
case "${ID:-}-${VERSION_ID:-}" in ubuntu-22.04|ubuntu-24.04) ;; *) fail "Ubuntu 22.04 or 24.04 required (found ${PRETTY_NAME:-unknown})." ;; esac
[[ -z "$DOMAIN" || "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "invalid --domain"
[[ -z "$EMAIL" || "$EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+$ ]] || fail "invalid --email"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
[ "$MEM_MB" -ge 1800 ] || echo "WARNING: only ${MEM_MB} MB RAM; composer/npm may fail. 2 GB+ recommended (add swap otherwise)."
export DEBIAN_FRONTEND=noninteractive

step "System packages"
apt-get update -y
apt-get install -y ca-certificates curl gnupg lsb-release unzip git rsync cron openssl software-properties-common nginx postgresql-client
if ! apt-cache show php8.3-fpm >/dev/null 2>&1; then
  add-apt-repository -y ppa:ondrej/php && apt-get update -y
fi
apt-get install -y php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath php8.3-gd php8.3-redis
PHP_SOCK=/run/php/php8.3-fpm.sock
cat > /etc/php/8.3/fpm/conf.d/90-jarvis.ini <<'INI'
upload_max_filesize = 4M
post_max_size = 8M
expose_php = Off
INI
systemctl enable --now php8.3-fpm && systemctl restart php8.3-fpm

step "Docker (PostgreSQL, Redis, AI engine run in containers)"
command -v docker >/dev/null || curl -fsSL https://get.docker.com | sh
systemctl enable --now docker
docker compose version >/dev/null || fail "docker compose plugin missing."

step "Composer and Node.js"
if ! command -v composer >/dev/null; then
  EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  [ "$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" = "$EXPECTED" ] || fail "composer installer checksum mismatch."
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y nodejs
fi

step "System user and project files in $TARGET"
id jarvis >/dev/null 2>&1 || useradd --system --create-home --shell /bin/bash --groups docker jarvis
usermod -aG docker jarvis
if [ "$SRC" != "$TARGET" ]; then
  mkdir -p "$TARGET"
  rsync -a --exclude '.git' --exclude 'apps/web/' --exclude '.env' --exclude 'node_modules' --exclude '.venv' "$SRC"/ "$TARGET"/
fi
chown -R jarvis:jarvis "$TARGET"
cd "$TARGET"
chmod +x scripts/*.sh deploy/*.sh

step "Secrets (.env at project root; created once, mode 600)"
if [ ! -f .env ]; then
  rnd() { openssl rand -hex "$1"; }
  SCHEME=http; [ -n "$DOMAIN" ] && [ -n "$EMAIL" ] && SCHEME=https
  HOSTNAME_URL="${DOMAIN:-$(curl -fsS https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')}"
  cat > .env <<ENV
COMPOSE_PROJECT_NAME=jarvis
DB_DATABASE=jarvis
DB_USERNAME=jarvis
DB_PASSWORD=$(rnd 24)
REDIS_PASSWORD=$(rnd 24)
DB_OWNER_PASSWORD=$(rnd 24)
DB_APP_PASSWORD=$(rnd 24)
DB_ADMIN_PASSWORD=$(rnd 24)
APP_ENV=production
APP_URL=${SCHEME}://${HOSTNAME_URL}
BRAND_NAME="JARVIS ONE"
AI_ENGINE_ENV=production
AI_ENGINE_URL=http://127.0.0.1:8000
AI_ENGINE_HMAC_SECRET=$(rnd 32)
AI_ENGINE_JWT_SECRET=$(rnd 32)
AI_VAULT_CURRENT=1
AI_VAULT_KEY_1=base64:$(openssl rand -base64 32)
ENV
  chmod 600 .env; chown jarvis:jarvis .env
  echo "Created $TARGET/.env  (BACK UP AI_VAULT_KEY_1 offline: losing it makes every stored provider key unreadable)."
else
  echo ".env exists; keeping it."
fi
set -a; . ./.env; set +a

step "Start PostgreSQL, Redis and the AI engine"
sudo -u jarvis docker compose -f infra/docker-compose.yml --env-file .env up -d --build
for i in $(seq 1 60); do
  [ "$(sudo -u jarvis docker inspect -f '{{.State.Health.Status}}' jarvis-postgres-1 2>/dev/null)" = healthy ] && break
  sleep 2; [ "$i" -lt 60 ] || fail "PostgreSQL container did not become healthy (docker compose -f infra/docker-compose.yml logs postgres)."
done

step "Database roles (jarvis_owner / jarvis_app / jarvis_admin_login)"
PGHOST=127.0.0.1 PGUSER="$DB_USERNAME" PGPASSWORD="$DB_PASSWORD" PGDATABASE="$DB_DATABASE" ./scripts/db-provision.sh

step "Laravel application (apps/web)"
if [ ! -f apps/web/artisan ]; then
  sudo -u jarvis -H env COMPOSER_ALLOW_SUPERUSER=0 ./scripts/bootstrap-web.sh
else
  echo "apps/web exists; refreshing overlay files only."
  sudo -u jarvis cp -R apps/web-overlay/. apps/web/
fi
cd apps/web
upsert() { if grep -q "^$1=" .env; then sed -i "s#^$1=.*#$2#" .env; else echo "$2" >> .env; fi; }
upsert APP_ENV         "APP_ENV=production"
upsert APP_DEBUG       "APP_DEBUG=false"
upsert APP_URL         "APP_URL=$APP_URL"
upsert LOG_LEVEL       "LOG_LEVEL=warning"
upsert REDIS_HOST      "REDIS_HOST=127.0.0.1"
upsert AI_ENGINE_URL   "AI_ENGINE_URL=http://127.0.0.1:8000"
upsert AI_VAULT_CURRENT "AI_VAULT_CURRENT=1"
upsert AI_VAULT_KEY_1  "AI_VAULT_KEY_1=$AI_VAULT_KEY_1"
grep -q '^MAIL_MAILER=' .env || echo "MAIL_MAILER=log" >> .env        # e-mails go to storage/logs until SMTP is set (see deploy/README.md)
chown jarvis:jarvis .env; chmod 640 .env; chgrp www-data .env

step "Dependencies and front-end build"
if [ "$PRODUCTION_DEPS" -eq 1 ]; then
  sudo -u jarvis -H composer install --no-dev --optimize-autoloader --no-interaction
else
  sudo -u jarvis -H composer install --optimize-autoloader --no-interaction
fi
sudo -u jarvis -H npm install --no-audit --no-fund
sudo -u jarvis -H npm run build

step "Migrations (as jarvis_owner) and seed"
sudo -u jarvis -H env DB_USERNAME=jarvis_owner DB_PASSWORD="$DB_OWNER_PASSWORD" php artisan migrate --seed --force
sudo -u jarvis -H php artisan tenancy:check
sudo -u jarvis -H php artisan storage:link --force 2>/dev/null || { rm -rf public/storage; ln -s "$(pwd)/storage/app/public" public/storage; } || true

step "Permissions and caches"
chown -R jarvis:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod g+s {} +
sudo -u jarvis -H php artisan config:cache
sudo -u jarvis -H php artisan view:cache
sudo -u jarvis -H php artisan route:cache || echo "route:cache skipped"
sudo -u jarvis -H php artisan event:cache || true

step "nginx"
ROOT="$TARGET/apps/web/public"
SERVER_NAME="${DOMAIN:-_}"
sed -e "s#@SERVER_NAME@#$SERVER_NAME#g" -e "s#@ROOT@#$ROOT#g" -e "s#@PHP_SOCK@#$PHP_SOCK#g" "$TARGET/deploy/nginx-site.conf.tpl" > /etc/nginx/sites-available/jarvis
ln -sf /etc/nginx/sites-available/jarvis /etc/nginx/sites-enabled/jarvis
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx && systemctl reload nginx

step "Queue supervisor and scheduler"
sed -e "s#@ROOT@#$TARGET/apps/web#g" "$TARGET/deploy/jarvis-horizon.service.tpl" > /etc/systemd/system/jarvis-horizon.service
echo "* * * * * jarvis cd $TARGET/apps/web && /usr/bin/php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/jarvis-scheduler
chmod 644 /etc/cron.d/jarvis-scheduler
systemctl daemon-reload
systemctl enable --now jarvis-horizon || echo "WARNING: horizon failed to start (journalctl -u jarvis-horizon); web chat works without it, but the Telegram bot needs it (queue)."

if [ -n "$DOMAIN" ] && [ -n "$EMAIL" ]; then
  step "HTTPS certificate (Let's Encrypt)"
  apt-get install -y certbot python3-certbot-nginx
  certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --non-interactive --redirect \
    || echo "WARNING: certbot failed (is the DNS A record for $DOMAIN pointing here?). The site works on HTTP; re-run this script after fixing DNS."
fi

if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
  ufw allow 80/tcp; ufw allow 443/tcp
fi

step "Self-check"
"$TARGET/deploy/healthcheck.sh" || echo "WARNING: some checks failed - see above."

cat <<DONE

JARVIS ONE is installed:  $APP_URL
Next steps
  1. Open the URL, register, then verify the address from the server (no mail server needed):
       cd $TARGET/apps/web && sudo -u jarvis php artisan user:verify you@example.com
  2. Log in, enable 2FA at /settings/security, then make yourself platform admin:
       sudo -u jarvis php artisan admin:promote you@example.com
  3. /admin -> plans (prices), AI Provider (add your API key, Test, add a model with a price, enable), then chat at /app/chat.
Secrets are in $TARGET/.env (mode 600). Re-run deploy/healthcheck.sh any time; update with deploy/update.sh.
DONE
