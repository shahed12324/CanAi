#!/usr/bin/env python3
"""The 20 validation areas of the v0.133.0 prompt, with SIMULATED evidence and REAL-DEVICE evidence kept in separate columns (Phase 14).

  python3 scripts/test-report.py                 # list the areas and what exists (no tests run)
  python3 scripts/test-report.py --run           # run every simulated check and write docs/DESKTOP_TEST_REPORT.md   (~6 min; needs the sandbox tools the verify script needs)
  python3 scripts/test-report.py --real DIR      # also read real-device results (JSON files written by `pytest tests/real` / the live-model test / validate-windows)

Rules the report follows (they are the point of this file):
  * 'SIM pass' means a fake backend / Linux / headless Chrome / mock model passed.  It is never shown as a real result.
  * 'REAL' says NOT RUN unless a result file from a real device exists for that area.  A skipped test is not a pass.
  * An area that cannot be automated (real microphone, phone + PC + server together) says MANUAL and points at the checklist; it is never marked as passed.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
AGENT = ROOT / "apps/pc-agent"
T = "tests/"


def py(*files, cwd="apps/pc-agent", extra=()):
    return ("pytest", cwd, [*files, *extra])


AREAS = [
    (1, "Python Agent unit tests", [py(*[T + f for f in ("test_guard.py", "test_actions.py", "test_taskengine.py", "test_fileops_docs.py", "test_protocol.py", "test_mode.py", "test_selfcheck.py",
        "test_streaming.py", "test_auditsync.py", "test_rotation.py", "test_crashreport.py", "test_release.py", "test_english_ui.py", "test_phase3.py", "test_report_script.py")])], None,
     "SIM: Linux, fake server, fake desktop backend"),
    (2, "Desktop UI tests", [py(T + "test_gui.py", T + "test_desktop_ui.py"), ("e2e", "apps/pc-agent", ["tests/e2e/gui.e2e.cjs"]), ("e2e-old", "apps/pc-agent", ["tests/e2e/gui.e2e.cjs"])], None,
     "SIM: headless Chrome, not the Tauri/WebView2 window on Windows"),
    (3, "Voice integration tests", [("node", "apps/pc-agent", ["tests/js/desktop-voice-character.test.js"]), ("e2e", "apps/pc-agent", ["tests/e2e/voice.e2e.cjs"])], None,
     "SIM: fake microphone, stubbed speech engine"),
    (4, "Browser automation tests", [py(T + "test_browserauto.py")], None, "SIM: headless Chrome against a local site"),
    (5, "Screen Vision tests", [py(T + "test_vision.py")], None, "SIM: real Tesseract on drawn screens; no real Windows screen/UI Automation"),
    (6, "AI Planner tests", [py("tests/test_planner.py", "tests/test_planner_acceptance.py", "tests/test_planner_computer.py", cwd="apps/ai-engine")], None, "SIM: mock model"),
    (7, "Laravel API tests", [("php", "apps/web-overlay", ["ReplyStyleTest", "ApprovalAlertTest", "PcAuditTest", "MobilePushTest", "PcControlTest", "PcWebTest"])], None,
     "SIM: mini PHP runner; the Laravel app was NEVER booted (Composer unavailable)"),
    (8, "PostgreSQL integration tests", [py("tests/db", cwd=".", extra=["-k", "pc_ or rotation or desktop_migrations"])], None, "REAL PostgreSQL 16 in the sandbox (not your production database / RLS roles)"),
    (9, "Tauri/Rust tests", [("cargo", "apps/desktop", [])], None, "SIM: unit tests of the shell logic; no window was ever opened"),
    (10, "Device pairing tests", [py(T + "test_protocol.py", T + "test_e2e_cli.py", extra=["-k", "pair"])], None, "SIM: fake server"),
    (11, "Mobile approval tests", [("vitest", "apps/mobile", [])], None, "SIM: vitest + tsc; no phone, no Expo push"),
    (12, "Emergency Stop tests", [py(T + "test_watchdog.py", T + "test_phase_d.py", T + "test_taskengine.py", extra=["-k", "stop or emergency or pause"])], None, "SIM: real Linux child processes"),
    (13, "Security and permission tests", [py(T + "test_security_hardening.py", T + "test_guard.py", T + "test_serverplan.py")], 13, "SIM: Linux; DPAPI/NTFS via fake backend"),
    (14, "Windows installation tests", [("release", ".", [])], 14, "SIM: only static release checks; installer never built or run"),
    (15, "Windows mouse/keyboard tests", [py(T + "test_devices_phase6.py", T + "test_validate.py")], 15, "SIM: fake input backend"),
    (16, "Real microphone tests", [], 16, "MANUAL: nothing automated can prove this (checklist E5-E7, E12)"),
    (17, "Live model API tests", [py("tests/live", cwd="apps/ai-engine")], 17, "SIM: none (mock model is area 6); real: tests/live with your key"),
    (18, "Cross-device end-to-end tests", [], 18, "MANUAL: phone + PC + server together (checklist E8-E11)"),
    (19, "Crash and recovery tests", [py(T + "test_recovery.py", T + "test_crashreport.py")], None, "SIM: real SIGKILL on Linux; not Windows process termination"),
    (20, "Performance and memory tests", [py(T + "test_performance.py")], None, "SIM: generous limits on a sandbox box; not a real PC with a real screen/mic"),
]
MANUAL = {16, 18}


def run_one(kind, cwd, args):
    env = dict(os.environ, NODE_PATH=os.environ.get("NODE_PATH", "/tmp/pupdir/node_modules"), JARVIS_BROWSER=os.environ.get("JARVIS_BROWSER", "/usr/bin/google-chrome"),
               JARVIS_TEST_PG_ADMIN_DSN=os.environ.get("JARVIS_TEST_PG_ADMIN_DSN", "postgresql://postgres@127.0.0.1:5432/postgres"))
    wd = ROOT / cwd
    if kind == "pytest":
        cmd = [sys.executable, "-m", "pytest", *args, "-q", "-p", "no:cacheprovider"]
    elif kind == "node":
        cmd = ["node", *args]
    elif kind in ("e2e", "e2e-old"):
        cmd = ["node", *args]
        if kind == "e2e-old":
            env["E2E_OLD_SERVER"] = "1"
    elif kind == "cargo":
        cmd = ["cargo", "test", "--offline"]
    elif kind == "vitest":
        cmd = ["bash", "-c", "npx vitest run && npx tsc --noEmit"]
    elif kind == "php":
        if not Path("/tmp/shim3.php").exists():
            return {"ok": None, "line": "SKIPPED: /tmp/shim3.php mini runner not present"}
        total, bad_line = 0, None
        for n in args:
            r = subprocess.run(["php", "/tmp/shim3.php", f"tests/Unit/{n}.php"], cwd=wd, capture_output=True, text=True, timeout=300)
            last = next((l for l in reversed((r.stdout + r.stderr).strip().splitlines()) if l.strip()), "")
            m = re.search(r"(\d+) tests", last)
            total += int(m.group(1)) if m else 0
            if " 0 failed" not in last and bad_line is None:
                bad_line = f"{n}: {last}"
        return {"ok": bad_line is None, "n": total, "skipped": 0, "line": bad_line or "all 0 failed"}
    elif kind == "release":
        cmd = [sys.executable, "scripts/release-check.py"]
    else:
        return {"ok": None, "line": "unknown kind"}
    try:
        r = subprocess.run(cmd, cwd=wd, env=env, capture_output=True, text=True, timeout=900)
    except (OSError, subprocess.TimeoutExpired) as e:
        return {"ok": None, "line": f"could not run: {type(e).__name__}"}
    out = r.stdout + r.stderr
    n = None
    m = re.findall(r"(\d+) passed", out)
    if m:
        n = int(m[-1])
    elif kind in ("e2e", "e2e-old"):
        n = len(re.findall(r"^OK", out, re.M))
    elif kind == "node":
        m = re.findall(r"# pass (\d+)", out); n = int(m[-1]) if m else None
    if kind == "release" and r.returncode == 0:
        n = 1                                                    # one consistency check script
    skipped = re.findall(r"(\d+) skipped", out)
    last = next((l for l in reversed(out.strip().splitlines()) if l.strip()), "")
    return {"ok": r.returncode == 0, "n": n, "skipped": int(skipped[-1]) if skipped else 0, "line": last[:100]}


def load_real(d: Path | None) -> dict[int, list[dict]]:
    out: dict[int, list[dict]] = {}
    if not d or not d.is_dir():
        return out
    for f in sorted(d.glob("*.json")):
        try:
            data = json.loads(f.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            continue
        rows = data if isinstance(data, list) else [data]
        for r in rows:
            if isinstance(r, dict) and r.get("area") in range(1, 21) and r.get("result") in ("pass", "fail"):
                out.setdefault(r["area"], []).append(r)
            elif isinstance(r, dict) and r.get("tool") == "jarvis-agent validate-windows" and r.get("real_device") is True:     # validate-windows report
                s = r.get("summary", {})
                out.setdefault(15, []).append({"result": "fail" if s.get("fail") else "pass", "platform": r.get("platform", ""), "when": r.get("when", ""), "test": "validate-windows", "host": ""})
    return out


def real_cell(area: int, real: dict[int, list[dict]]) -> str:
    if area in MANUAL and area not in real:
        return "MANUAL / NOT RUN"
    rows = real.get(area)
    if not rows:
        return "NOT RUN"
    p = sum(r["result"] == "pass" for r in rows); f = sum(r["result"] == "fail" for r in rows)
    last = max(rows, key=lambda r: r.get("when", ""))
    return f"{'FAIL' if f else 'PASS'} ({p} pass, {f} fail; last {last.get('when', '')[:10]} on {last.get('platform', '')[:40]})"


def main(argv: list[str]) -> int:
    run = "--run" in argv
    real_dir = Path(argv[argv.index("--real") + 1]) if "--real" in argv else None
    real = load_real(real_dir)
    rows, bad = [], False
    for n, name, cmds, _real_area, note in AREAS:
        results = []
        if run:
            for c in cmds:
                results.append(run_one(*c))
        if not cmds:
            sim = "none (cannot be automated)"
        elif not run:
            sim = f"{len(cmds)} check(s) defined, not run"
        else:
            ok_all = all(r["ok"] for r in results if r["ok"] is not None)
            skipped_any = any(r["ok"] is None for r in results)
            tot = sum(r.get("n") or 0 for r in results)
            sk = sum(r.get("skipped") or 0 for r in results)
            if tot == 0 and sk > 0 and ok_all:
                rows.append((n, name, f"NOT RUN (all {sk} skipped)", real_cell(n, real), note))
                continue
            if not ok_all:
                bad = True
            sim = ("FAIL" if not ok_all else ("PASS" if not skipped_any else "PARTIAL")) + f" ({tot} checks" + (f", {sk} skipped" if sk else "") + ")"
        rows.append((n, name, sim, real_cell(n, real), note))
    out = ["# CAN AI Desktop — the 20 validation areas, simulated vs real-device", "",
           f"Generated {time.strftime('%Y-%m-%d %H:%M')} by `scripts/test-report.py" + (" --run" if run else "") + "`.", "",
           "**Read this first.** The *Simulated* column is what runs on a Linux sandbox with fakes (fake Windows input, fake server, fake microphone, mock model, headless Chrome). "
           "It finds bugs in the code, but it is **not evidence about a real Windows PC, phone, microphone or model**. The *Real device* column only ever says PASS/FAIL if a result file "
           "from a real run exists (`JARVIS_REAL_DEVICE=1 python -m pytest tests/real`, `apps/ai-engine/tests/live`, `jarvis-agent validate-windows --yes`); otherwise it says NOT RUN. "
           "A skipped test is never counted as a pass.", "",
           "| # | Area | Simulated | Real device | What the simulated part is |", "|---|---|---|---|---|"]
    for r in rows:
        out.append("| " + " | ".join(str(x).replace("|", "/") for x in r) + " |")
    out += ["", "## How to fill the Real device column", "",
            "1. Windows PC: `set JARVIS_REAL_DEVICE=1` then `python -m pytest apps/pc-agent/tests/real -v` (areas 13, 14, 15; set `JARVIS_INSTALLED_AGENT` for 14). Results land in `%USERPROFILE%\\.jarvis-agent\\real-results\\`.",
            "2. Live model: `JARVIS_LIVE_MODEL=1 JARVIS_LIVE_PROVIDER=... JARVIS_LIVE_API_KEY=... JARVIS_LIVE_MODEL_NAME=... python -m pytest apps/ai-engine/tests/live -v -s` (area 17).",
            "3. Microphone, phone approval and cross-device runs (16, 18): follow `docs/DESKTOP_WINDOWS_CHECKLIST.md` (E5-E12) and record what you saw; these cannot be automated.",
            "4. Copy the JSON result files into one folder and run `python3 scripts/test-report.py --real that-folder` to regenerate this table."]
    text = "\n".join(out) + "\n"
    print(text)
    if run:
        (ROOT / "docs/DESKTOP_TEST_REPORT.md").write_text(text, encoding="utf-8")
        print("written docs/DESKTOP_TEST_REPORT.md")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
