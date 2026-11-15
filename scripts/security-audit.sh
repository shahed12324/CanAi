#!/usr/bin/env bash
# Phase 28: runs every standing security check we can run without a live site.  Exit code is non-zero if any fails.
#   JARVIS_TEST_PG_ADMIN_DSN=postgresql://postgres@127.0.0.1:5432/postgres scripts/security-audit.sh
set -u
cd "$(dirname "$0")/.."
PY="${PYTHON:-python3}"
fail=0
step() { echo; echo "== $1"; shift; "$@" || { echo "FAILED: $*"; fail=1; }; }

step "DB audit (definer functions, grants, views, roles, RLS)" "$PY" -m pytest tests/db/test_security_audit.py tests/db/test_rls.py -q
step "PHP static audit (blade, SQL, eval, secrets, admin guards, uploads)" php scripts/runpure.php apps/web-overlay/tests/Unit/SecurityAuditTest.php
step "Engine audit (signed routes, no exec, no bidi, no secrets)" "$PY" -m pytest apps/ai-engine/tests/test_security_audit.py -q
if command -v bandit >/dev/null 2>&1 || "$PY" -m bandit --version >/dev/null 2>&1; then
  step "bandit (Python static analysis)" "$PY" -m bandit -r apps/ai-engine/app -q -lll
fi
if "$PY" -m pip_audit --version >/dev/null 2>&1; then
  step "pip-audit (installed Python packages)" "$PY" -m pip_audit --skip-editable
fi
if command -v npm >/dev/null 2>&1 && [ -f apps/mobile/package-lock.json ]; then
  step "npm audit (mobile, high+)" bash -c 'cd apps/mobile && npm audit --package-lock-only --audit-level=high'
fi
echo; [ "$fail" -eq 0 ] && echo "ALL SECURITY CHECKS PASSED" || echo "SOME SECURITY CHECKS FAILED"
exit "$fail"
