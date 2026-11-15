#!/usr/bin/env bash
# One command that re-runs every automated check of the CAN AI Desktop and prints a summary you can paste into a report.
# Needs: python3 + pytest, node 20+, google-chrome + `npm i puppeteer-core` (set NODE_PATH), optional PostgreSQL (JARVIS_TEST_PG_ADMIN_DSN), optional cargo.
# What it can NOT do: run anything on Windows, a real microphone, a real phone.  See docs/DESKTOP_WINDOWS_CHECKLIST.md for those.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/apps/pc-agent"
fail=0
run() { local name="$1"; shift; local out; out="$("$@" 2>&1)"; local rc=$?; echo "[$([ $rc -eq 0 ] && echo PASS || echo FAIL)] $name :: $(echo "$out" | grep -v '^$' | tail -1 | cut -c1-110)"; [ $rc -eq 0 ] || fail=1; }
e2e() { local out rc; out="$("$@" 2>&1)"; rc=$?; [ $rc -ne 0 ] && { echo "exit code $rc after $(echo "$out" | grep -c '^OK') OK lines"; echo "$out" | tail -3; return 1; }; echo "$out" | grep -qE 'FAIL|CRASH' && { echo "$out" | grep -E 'FAIL|CRASH' | head -3; return 1; }; echo "$out" | grep -c '^OK' | sed 's/$/ checks OK/'; [ "$(echo "$out" | grep -c '^OK')" -gt 0 ]; }
run "agent unit/integration (pytest)"          python3 -m pytest tests -q -p no:cacheprovider
run "release consistency (versions, CSP, workflow safety)" python3 ../../scripts/release-check.py
run "voice+character logic (node)"             node --test tests/js/*.test.js
if [ -n "${NODE_PATH:-}" ] && command -v "${CHROME:-google-chrome}" >/dev/null; then
  run "window e2e in headless Chrome"          e2e node tests/e2e/gui.e2e.cjs
  run "window e2e vs OLD server"               env E2E_OLD_SERVER=1 bash -c "$(declare -f e2e); e2e node tests/e2e/gui.e2e.cjs"
  run "voice e2e (fake mic, stubbed speech)"   e2e node tests/e2e/voice.e2e.cjs
else echo "[SKIP] Chrome e2e (set NODE_PATH to puppeteer-core's node_modules and install Chrome)"; fi
if [ -n "${JARVIS_TEST_PG_ADMIN_DSN:-}" ]; then
  run "database rules (PostgreSQL pc_* + rotation + migration files)" bash -c "cd '$ROOT' && python3 -m pytest tests/db -q -p no:cacheprovider -k 'pc_ or rotation or desktop_migrations'"
else echo "[SKIP] database tests (set JARVIS_TEST_PG_ADMIN_DSN=postgresql://postgres@127.0.0.1:5432/postgres)"; fi
if command -v cargo >/dev/null && [ -d ../desktop ]; then run "Tauri shell (cargo test --offline)" bash -c "cd ../desktop && cargo test --offline -q"; else echo "[SKIP] cargo tests"; fi
if [ -d ../ai-engine ] && python3 -c "import fastapi" 2>/dev/null; then run "planner (ai-engine, mock model)" bash -c "cd ../ai-engine && python3 -m pytest tests/test_planner.py tests/test_planner_computer.py tests/test_planner_acceptance.py -q -p no:cacheprovider"; else echo "[SKIP] planner tests (ai-engine dependencies missing)"; fi
if [ -d ../mobile/node_modules ]; then run "phone app (Expo): vitest + tsc" bash -c "cd ../mobile && npx vitest run 2>&1 | grep -E 'Tests ' && npx tsc --noEmit && echo tsc-ok"; else echo "[SKIP] phone app tests (npm ci in apps/mobile)"; fi
if [ -d ../web-overlay ] && command -v php >/dev/null && [ -f /tmp/shim3.php ]; then run "PHP mini-runner: reply style + approval alert + push" bash -c "cd ../web-overlay && for f in ReplyStyleTest ApprovalAlertTest PcAuditTest MobilePushTest PcControlTest PcWebTest; do php /tmp/shim3.php tests/Unit/\$f.php | tail -1; done | grep -v ' 0 failed' | (! grep .) && echo 'all 0 failed'"; else echo "[SKIP] PHP mini-runner tests"; fi
echo; [ $fail -eq 0 ] && echo "ALL EXECUTED CHECKS PASSED (skipped ones are listed above)" || echo "SOME CHECKS FAILED"
exit $fail
