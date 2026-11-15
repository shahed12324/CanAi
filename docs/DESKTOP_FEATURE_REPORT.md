# CAN AI Desktop — feature completion report (v0.133.0, 2026-11-14)

Status words follow the specification: **Implemented / Partial / Not implemented** × **Tested / Not tested / Blocked**. "Tested" always means *on Linux with fakes* unless stated; **no feature has been run on real Windows, a real microphone or a real phone**. Paths are relative to the project root; test evidence = `scripts/desktop-verify.sh` (last run in this session: pytest 250 pass, Chrome e2e 64 + 50 (old server) + 20 voice checks, node 1 file/26 checks, PostgreSQL pc_*/rotation 146 pass, cargo 6 pass).

## 1. Interface and companion
| Feature | Implementation | Tested | Files |
|---|---|---|---|
| Local window with 14 menus, bilingual (bn/en), dark/light, compact, reduced motion | Implemented | Tested (Chrome) | `apps/pc-agent/jarvis_agent/ui/{index.html,app.css,app.js}` |
| Companion / Agent mode switch | Implemented | Tested | `jarvis_agent/config.py`, `actions.py`, `runner.py`, `tests/test_mode.py` |
| Animated character, 5 states, personality, colors | Implemented | Tested (logic + Chrome); visual quality **not judged on a real screen** | `ui/character.js`, `tests/js/` |
| Chat / conversations / models / credits | Client **Implemented**; server **Implemented but never run** | Client tested vs fake server; server **Not tested** | `ui/app.js`, `apps/web-overlay/app/Http/Controllers/Api/Pc/DesktopController.php` |
| Agents, Workflows, Memory, Tools, Approvals (read-only) screens | Same as above | Same | same |
| Streaming replies | Not implemented | – | – |
| Settings (theme, character, voice, performance, always-on-top) | Implemented | Tested; always-on-top only unit-tested | `prefs.py`, `ui/app.js`, `apps/desktop/src/main.rs` |

## 2. Voice
| Feature | Implementation | Tested | Files |
|---|---|---|---|
| Tap-to-talk, push-to-talk, silence endpointing, barge-in, stop words, mute | Implemented | Wiring tested with fake mic + stubbed speech; **audio not tested** | `ui/voice.js`, `ui/voice-session.js` |
| Wake word "Hey CAN AI", hands-free with consent | Implemented | Logic tested; **never heard a real voice** | same |
| Browser STT/TTS | Implemented | **Blocked** by lack of real browser speech services; WebView2 support unknown | – |
| Cloud STT/TTS fallback | Client Implemented; server Implemented never run | Client tested; server Not tested | `DesktopController::listen/speak` |
| Bengali recognition / voice quality | – | **Not tested** | – |
| True lip-sync for cloud TTS | Not implemented (wave only) | – | – |

## 3. Computer control
| Feature | Implementation | Tested | Files |
|---|---|---|---|
| Plan → approve → act → verify → report engine, retries, checkpoints, audit chain | Implemented | Tested | `taskengine.py`, `verify.py`, `tests/test_taskengine.py` |
| File operations (read/write/copy/move/trash/mkdir, xlsx/docx) | Implemented | Tested (Linux) | `actions.py`, `fileops.py`, `docs.py` |
| Open app, browser navigate, run command (allow-listed, no shell) | Implemented | Tested (fake) | `actions.py` |
| Screenshot, mouse, keyboard (guarded) | Implemented | Fake backend only; **real backends untested** | `screen.py`, `inputctl.py`, `devices.py` |
| Window observation, wait-for-window, window-based verification | Implemented | Tested (fake) | `observe.py` |
| Pause / continue, live progress | Implemented | Tested | `pause.py`, `progress.py` |
| Emergency stop (window, CLI, file, server) | Implemented | Tested on Linux; Windows kill untested | `stop.py`, `watchdog.py` |
| Screen understanding (OCR/vision), element finding | **Not implemented** | – | – |
| Browser/form automation inside pages | **Not implemented** | – | – |
| LLM-planned computer control | **Not implemented on purpose** (planner = file kinds) | – | `apps/ai-engine/app/planner/plan.py` |
| Server queue for the two new kinds | Implemented | DB-tested (PostgreSQL 16) | `database/sql/phase33a.up.sql` |

## 4. Integration and security
| Feature | Implementation | Tested | Files |
|---|---|---|---|
| Device pairing, revoke | Implemented (earlier phases) | Tested (DB + agent) | `phase26a.up.sql`, `AgentController.php` |
| Token rotation | Implemented | Tested (DB, agent, e2e) | `phase33b.up.sql`, `rotation.py` |
| Signed approvals and plans, replay/expiry protection | Implemented | Tested | `serverplan.py`, `taskengine.py` |
| Mobile approval → agent execution | Implemented (earlier phases) | Halves tested separately; **end-to-end not tested** | `Api/Mobile/PcActionController.php` |
| Local ↔ server audit sync (copy + compare; not a merge) | Implemented (0.143.0) | Tested against a fake server + PostgreSQL | `auditsync.py`, `phase37a.sql`, `test_auditsync.py`, `test_pc_audit_chain.py`; real server NOT run |
| Push to desktop on approval | Not implemented (3-s polling) | – | – |

## 5. Packaging
| Feature | Implementation | Tested | Files |
|---|---|---|---|
| Python-based Windows package (.bat) | Implemented | **Not tested on Windows** | `CANAI-Desktop-Windows.zip` |
| PyInstaller exe + Tauri NSIS/MSI via GitHub Actions | Implemented | **Never run** (Blocked: no Windows/GitHub access here) | `.github/workflows/desktop-release.yml`, `apps/pc-agent/packaging/` |
| Code signing, auto-update | Not implemented | – | – |

## Known issues and limitations (also see `DESKTOP_SECURITY_AUDIT.md`)
- New Laravel desktop API: unproven against a live app; first real call may reveal small bugs.
- `phase33a`/`phase33b` down-migrations never executed.
- `persona`/`source` chat fields are accepted but unused server-side.
- The headless-Chrome e2e had timing flakes (fixed by retrying detached clicks); the window re-renders every 5 s when something changed.
- Bengali text in the window is correct for the cases tested, but font coverage on Windows is unchecked.
