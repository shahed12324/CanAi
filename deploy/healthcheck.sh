#!/usr/bin/env bash
# Quick health report. Exit 0 = all good. Safe to run any time (read-only).
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ok=0; bad=0
check() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then printf '  [ OK ] %s\n' "$name"; ok=$((ok+1)); else printf '  [FAIL] %s\n' "$name"; bad=$((bad+1)); fi; }
echo "JARVIS ONE health"
check "nginx running"            systemctl is-active --quiet nginx
check "php-fpm running"          systemctl is-active --quiet php8.3-fpm
check "docker: postgres healthy" test "$(docker inspect -f '{{.State.Health.Status}}' jarvis-postgres-1 2>/dev/null)" = healthy
check "docker: redis healthy"    test "$(docker inspect -f '{{.State.Health.Status}}' jarvis-redis-1 2>/dev/null)" = healthy
check "docker: ai-engine healthy" test "$(docker inspect -f '{{.State.Health.Status}}' jarvis-ai-engine-1 2>/dev/null)" = healthy
check "AI engine answers /healthz" curl -fsS --max-time 5 http://127.0.0.1:8000/healthz
check "web answers (login page)" curl -fsS --max-time 10 -H 'Host: localhost' -o /dev/null http://127.0.0.1/login
if [ -f "$ROOT/apps/web/artisan" ]; then
  cd "$ROOT/apps/web" || exit 1
  A="php artisan"; [ "$(id -u)" -eq 0 ] && A="sudo -u jarvis php artisan"
  check "tenant isolation (tenancy:check)" $A tenancy:check
  check "credit ledger consistent (credits:reconcile)" $A credits:reconcile
  check "scheduler registered"     bash -c "$A schedule:list | grep -q credits:release-stale"
fi
echo "ok=$ok failed=$bad"
[ "$bad" -eq 0 ]
