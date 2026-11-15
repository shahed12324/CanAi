#!/usr/bin/env bash
# JARVIS ONE self-host installer. Needs Docker (+ compose plugin). Idempotent: re-running keeps your .env and data.
#   ./install.sh [--domain app.example.com --email you@example.com] [--license-key JVSH1....] [--no-render]
#   ./install.sh --domain app.example.com --behind-proxy 8080   (cPanel/Apache/nginx already owns 80/443: app listens on 127.0.0.1:8080 only, you add the proxy + TLS)
set -Eeuo pipefail
trap 'echo "ERROR at line $LINENO: $BASH_COMMAND" >&2' ERR
cd "$(dirname "${BASH_SOURCE[0]}")"
DOMAIN=""; EMAIL=""; LICENSE=""; RENDER=1; PROXY_PORT=""
while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="${2:?}"; shift 2 ;;
    --email) EMAIL="${2:?}"; shift 2 ;;
    --license-key) LICENSE="${2:?}"; shift 2 ;;
    --no-render) RENDER=0; shift ;;
    --behind-proxy) PROXY_PORT="${2:?}"; shift 2 ;;
    -h|--help) sed -n 2,3p "$0"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done
fail() { echo "ERROR: $*" >&2; exit 1; }
step() { printf '\n==> %s\n' "$*"; }
command -v docker >/dev/null || fail "Docker is not installed (https://docs.docker.com/engine/install/)."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 plugin is missing."
command -v openssl >/dev/null || fail "openssl is required."
[ -z "$PROXY_PORT" ] || { [[ "$PROXY_PORT" =~ ^[0-9]{4,5}$ ]] && [ "$PROXY_PORT" -ge 1024 ] && [ "$PROXY_PORT" -le 65535 ] && [ -n "$DOMAIN" ]; } || fail "--behind-proxy needs a port 1024-65535 and --domain."
[ -z "$DOMAIN" ] || [[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || fail "invalid --domain."
[ -z "$EMAIL" ] || [[ "$EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+$ ]] || fail "invalid --email."
[ -z "$LICENSE" ] || [[ "$LICENSE" =~ ^JVSH1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$ ]] || fail "licence key looks wrong (must start with JVSH1.)."
DC=(docker compose --env-file .env -f docker-compose.yml)

step "Secrets (.env, mode 600; created once)"
if [ ! -f .env ]; then
  rnd() { openssl rand -hex "$1"; }
  SCHEME=http; WEB_BIND="0.0.0.0:80"; PROFILES=""
  HOST_NAME="${DOMAIN:-$(hostname -I 2>/dev/null | awk '{print $1}')}"; HOST_NAME="${HOST_NAME:-localhost}"
  if [ -n "$DOMAIN" ]; then SCHEME=https; WEB_BIND="127.0.0.1:8080"; PROFILES="https"; fi
  if [ -n "$PROXY_PORT" ]; then WEB_BIND="127.0.0.1:${PROXY_PORT}"; PROFILES=""; fi   # no Caddy: your proxy owns 80/443 and TLS
  umask 077
  cat > .env <<ENV
COMPOSE_PROJECT_NAME=jarvis
JARVIS_VERSION=local
APP_URL=${SCHEME}://${HOST_NAME}
APP_KEY=base64:$(openssl rand -base64 32)
DOMAIN=${DOMAIN}
WEB_BIND=${WEB_BIND}
COMPOSE_PROFILES=${PROFILES}
WITH_RENDER=${RENDER}
DB_DATABASE=jarvis
DB_USERNAME=jarvis
DB_PASSWORD=$(rnd 24)
DB_OWNER_PASSWORD=$(rnd 24)
DB_APP_PASSWORD=$(rnd 24)
DB_ADMIN_PASSWORD=$(rnd 24)
REDIS_PASSWORD=$(rnd 24)
AI_ENGINE_HMAC_SECRET=$(rnd 32)
AI_ENGINE_JWT_SECRET=$(rnd 32)
AI_VAULT_CURRENT=1
AI_VAULT_KEY_1=base64:$(openssl rand -base64 32)
MAIL_MAILER=log
JARVIS_LICENSE_PUBKEY=
ENV
  echo "Created $(pwd)/.env — BACK UP AI_VAULT_KEY_1 and APP_KEY offline (losing them makes stored keys/sessions unreadable)."
else
  echo ".env exists; keeping it."
fi
if [ -n "$LICENSE" ]; then printf '%s' "$LICENSE" > .license-key.pending; chmod 600 .license-key.pending; fi

step "Build images (first time takes several minutes)"
"${DC[@]}" build

step "Start database and cache, create roles"
"${DC[@]}" up -d postgres redis
"${DC[@]}" --profile setup run --rm provision

step "Migrate (as jarvis_owner), seed, isolation check"
"${DC[@]}" --profile setup run --rm migrate

step "Start the application"
"${DC[@]}" up -d
if [ -f .license-key.pending ]; then
  "${DC[@]}" exec -T web php artisan selfhost:license "$(cat .license-key.pending)" && rm -f .license-key.pending \
    || echo "WARNING: licence key was not accepted (is JARVIS_LICENSE_PUBKEY set in .env? then: ./license.sh <key>)."
fi

step "Self-check"
./healthcheck.sh || echo "WARNING: some checks failed — see above."
set -a; . ./.env; set +a
cat <<DONE

JARVIS ONE is installed:  $APP_URL
Next steps
  1. Open the URL, register, then verify the address from the server (no mail server needed):
       ./artisan.sh user:verify you@example.com
  2. Log in, enable 2FA at /settings/security, then make yourself platform admin:
       ./artisan.sh admin:promote you@example.com
  3. /admin -> plans, AI Provider (add your key, Test, add a model with a price, enable), then chat at /app/chat.
  4. Backups: ./backup.sh (schedule it: see README.md). Updates: ./update.sh <new-zip-or-dir>.
${PROXY_PORT:+Proxy: point your Apache/nginx for $DOMAIN at http://127.0.0.1:$PROXY_PORT (see selfhost/CPANEL.md); TLS is yours (AutoSSL).}
${DOMAIN:+${PROXY_PORT:-HTTPS: make sure the DNS A record of $DOMAIN points to this server and ports 80/443 are open; the certificate is issued automatically.}}
DONE
