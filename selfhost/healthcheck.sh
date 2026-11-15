#!/usr/bin/env bash
# Read-only health report. Exit 0 = all good.
set -u
cd "$(dirname "${BASH_SOURCE[0]}")"
DC=(docker compose --env-file .env -f docker-compose.yml)
ok=0; bad=0
check() { local n="$1"; shift; if "$@" >/dev/null 2>&1; then printf '  [ OK ] %s\n' "$n"; ok=$((ok+1)); else printf '  [FAIL] %s\n' "$n"; bad=$((bad+1)); fi; }
echo "JARVIS ONE (self-host) health"
for s in postgres redis ai-engine web nginx horizon scheduler; do
  check "container running: $s" bash -c "[ \"\$(${DC[*]} ps --status running --format '{{.Service}}' | grep -x $s)\" = $s ]"
done
check "postgres accepts connections" "${DC[@]}" exec -T postgres sh -c 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
check "AI engine /healthz" "${DC[@]}" exec -T ai-engine python -c "import urllib.request as u; u.urlopen('http://127.0.0.1:8000/healthz', timeout=3)"
check "web answers (login page)" "${DC[@]}" exec -T nginx wget -q -O /dev/null http://127.0.0.1/login
check "tenant isolation (tenancy:check)" "${DC[@]}" exec -T web php artisan tenancy:check
check "credit ledger consistent" "${DC[@]}" exec -T web php artisan credits:reconcile
echo "ok=$ok failed=$bad"
[ "$bad" -eq 0 ]
