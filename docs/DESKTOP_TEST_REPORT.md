# CAN AI Desktop — the 20 validation areas, simulated vs real-device

Generated 2026-11-15 18:04 by `scripts/test-report.py --run`.

**Read this first.** The *Simulated* column is what runs on a Linux sandbox with fakes (fake Windows input, fake server, fake microphone, mock model, headless Chrome). It finds bugs in the code, but it is **not evidence about a real Windows PC, phone, microphone or model**. The *Real device* column only ever says PASS/FAIL if a result file from a real run exists (`JARVIS_REAL_DEVICE=1 python -m pytest tests/real`, `apps/ai-engine/tests/live`, `jarvis-agent validate-windows --yes`); otherwise it says NOT RUN. A skipped test is never counted as a pass.

| # | Area | Simulated | Real device | What the simulated part is |
|---|---|---|---|---|
| 1 | Python Agent unit tests | PASS (157 checks) | NOT RUN | SIM: Linux, fake server, fake desktop backend |
| 2 | Desktop UI tests | PASS (217 checks) | NOT RUN | SIM: headless Chrome, not the Tauri/WebView2 window on Windows |
| 3 | Voice integration tests | PASS (27 checks) | NOT RUN | SIM: fake microphone, stubbed speech engine |
| 4 | Browser automation tests | PASS (15 checks) | NOT RUN | SIM: headless Chrome against a local site |
| 5 | Screen Vision tests | PASS (25 checks) | NOT RUN | SIM: real Tesseract on drawn screens; no real Windows screen/UI Automation |
| 6 | AI Planner tests | PASS (82 checks) | NOT RUN | SIM: mock model |
| 7 | Laravel API tests | PASS (54 checks) | NOT RUN | SIM: mini PHP runner; the Laravel app was NEVER booted (Composer unavailable) |
| 8 | PostgreSQL integration tests | PASS (169 checks) | NOT RUN | REAL PostgreSQL 16 in the sandbox (not your production database / RLS roles) |
| 9 | Tauri/Rust tests | PASS (9 checks) | NOT RUN | SIM: unit tests of the shell logic; no window was ever opened |
| 10 | Device pairing tests | PASS (4 checks) | NOT RUN | SIM: fake server |
| 11 | Mobile approval tests | PASS (38 checks) | NOT RUN | SIM: vitest + tsc; no phone, no Expo push |
| 12 | Emergency Stop tests | PASS (17 checks) | NOT RUN | SIM: real Linux child processes |
| 13 | Security and permission tests | PASS (166 checks) | NOT RUN | SIM: Linux; DPAPI/NTFS via fake backend |
| 14 | Windows installation tests | PASS (1 checks) | NOT RUN | SIM: only static release checks; installer never built or run |
| 15 | Windows mouse/keyboard tests | PASS (35 checks) | NOT RUN | SIM: fake input backend |
| 16 | Real microphone tests | none (cannot be automated) | MANUAL / NOT RUN | MANUAL: nothing automated can prove this (checklist E5-E7, E12) |
| 17 | Live model API tests | NOT RUN (all 8 skipped) | NOT RUN | SIM: none (mock model is area 6); real: tests/live with your key |
| 18 | Cross-device end-to-end tests | none (cannot be automated) | MANUAL / NOT RUN | MANUAL: phone + PC + server together (checklist E8-E11) |
| 19 | Crash and recovery tests | PASS (16 checks) | NOT RUN | SIM: real SIGKILL on Linux; not Windows process termination |
| 20 | Performance and memory tests | PASS (19 checks) | NOT RUN | SIM: generous limits on a sandbox box; not a real PC with a real screen/mic |

## How to fill the Real device column

1. Windows PC: `set JARVIS_REAL_DEVICE=1` then `python -m pytest apps/pc-agent/tests/real -v` (areas 13, 14, 15; set `JARVIS_INSTALLED_AGENT` for 14). Results land in `%USERPROFILE%\.jarvis-agent\real-results\`.
2. Live model: `JARVIS_LIVE_MODEL=1 JARVIS_LIVE_PROVIDER=... JARVIS_LIVE_API_KEY=... JARVIS_LIVE_MODEL_NAME=... python -m pytest apps/ai-engine/tests/live -v -s` (area 17).
3. Microphone, phone approval and cross-device runs (16, 18): follow `docs/DESKTOP_WINDOWS_CHECKLIST.md` (E5-E12) and record what you saw; these cannot be automated.
4. Copy the JSON result files into one folder and run `python3 scripts/test-report.py --real that-folder` to regenerate this table.
